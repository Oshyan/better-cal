# Changelog

Better-Cal uses [semantic versioning](https://semver.org). Minor versions bring features, and patch versions generally bring bug fixes and refinements, security fixes included. Every release is a git tag (`v1.0.0`) and a GitHub release; the running version is the `VERSION` file at the repository root, which the Settings page shows. Database migrations always run forward, so you can skip versions. [Updating](docs/install.md#updating) has the steps, with a backup first and what to do if something goes wrong.

Security fixes are tracked privately as GitHub security advisories until they ship, then published; [SECURITY.md](SECURITY.md#past-advisories) lists past advisories with the affected and patched versions.

## Unreleased

- **More little fixes from [#183](https://github.com/Oshyan/better-cal/issues/183)'s findings 😅**
  - Overnight events (10 PM to 1 AM) keep their time: a chip in month view, a block on each day in week and day views, which you can move and resize.
  - Week view's all-day and multi-day bars can be moved and resized; in day view, dragging across midnight makes a timed event.
  - The agenda keeps loading as you scroll, and no longer shows months it hasn't loaded as empty.
  - A move no longer briefly snaps back when a refresh lands, and an event deleted elsewhere disappears even if it began before the dates on screen.
  - Smaller: away times and plugin bands load for the right months after quick moves, the phone's day title skips hidden calendars, month tokens give other zones' times on your clock, and the month label and mini-month follow view switches and "Show past events".

## 1.4.1 (2026-10-29)

- **Fixes from a review of every view ([#183](https://github.com/Oshyan/better-cal/issues/183)):** it turns out building a calendar view *is* easy, just deceptively so. The gotchas aren't hard to fix once you know where to look. Sorry for missing them until now! 😅 More follow in the next releases; let me know if you see anything else that looks off.
  - Weeks you'd seen earlier no longer show an old version after a change.
  - Next, the arrow keys, switching views and a reload start from where you've scrolled to.
  - Away and busy times and plugin bands show in week and day views and in "+N more" lists, and no longer end a day early.
  - Day view's month follows Today, the arrows and jumps, so tide shading and away times do too.
  - A drag let go outside the calendar no longer moves the event; read-only events no longer drag only to fail.
  - Grouped holidays stay a day label, and day view's date header stays above the timeline.
- **The event you point at comes forward** in week and day views, at full strength and on top. A tide's label can still be clicked through an event.
- **Feed problems in plain words:** a notice in the feed's settings says what went wrong, with Try again; the sidebar shows a compact version with Details.
- **Tides are easier to see:** a solid line in the calendar's color, labelled with height and time, and "Shade around each tide" now shades the timeline around it.
- **Calendar names get the sidebar's full width:** Only and the gear appear on hover, and web-address calendars and their events show a link icon.
- **Undo says what it would undo,** like "Moved · Nov 6 → Nov 17".
- **The sidebar stays on Settings, Review and the other pages** on a computer.
- **A plugin's first run starts when you turn it on,** so Tides' station list is there right away.
- **Times stored in UTC read on your own clock everywhere,** including tide labels, Travel's "leave by", Lint, Activity, Review and the MCP server.
- **Fixes:** Today works in day view from far away, and day view shows tide shading and away times after a reload. A plugin run started by Run now waits for the worker's lock, so it can't write during an update.

Operators: one database change (feeds remember when they last updated), applied automatically by `update.php` or the database update page.

## 1.4.0 (2026-10-27)

Month rows that fit their days, event links and paste anywhere into quick add, suggestions as you type, a number on the app icon, and a phone 3-day view that's easier to tap.

- **Month rows that fit their days:** on a computer, the switch in the corner above the week numbers (or Shift+H) makes each week in month view as tall as its busiest day needs, so 25 tasks on one day all show instead of "+20 more". Switch back the same way.
- **Event links become events:** paste or share a Luma, Partiful, Eventbrite, Meetup or Pie link into quick add and it fills in the title, time, place and link from the page, for you to check before saving. [Event links](docs/quick-add.md#event-links) lists the sites.
- **Paste anywhere:** on a computer, Cmd/Ctrl+V on the calendar opens quick add with the link or text you copied, already read. Enter adds it.
- **Quick add suggests as you type:** `/` lists your calendars, `#` your tags and `@` your people, in quick add and the editor's text box. Enter or Tab fills one in. `@"Full Name"` links someone whose first name another person shares.
- **Jump to a view as well as a date:** the jump box (`g`) and the command palette take a view at either end, like "day view of June 9th", "september month view" or "agenda from next tuesday".
- **Save from the editor with Cmd/Ctrl+Enter:** from any field, including the plain-language box, which is read first.
- **A number on the app icon:** Settings, Notifications, Show on app icon: Review items waiting, events today, or events still to come today, on the installed app's icon on a computer, iPhone or iPad. Off by default. [The app and reminders](docs/app-and-reminders.md#changing-notification-settings) says where it shows.
- **Holidays stand out:** a holiday's day has a stripe across the top of its header in the holiday calendar's color, in every view. In the phone's 3-day view the holiday is a flag you tap to see it, instead of a name too squeezed to read.
- **Phone taps in a day's header:** weather and other context tokens open when tapped, a tap that misses them in the header no longer starts a new event, and pressing and holding a date offers New event or Day view.
- **Reorder the sidebar:** drag a folder's header, or All calendars, People or Plugins, to a new place in the sidebar, or use Sidebar order, Edit, under Calendars & folders.
- **Hide cancelled events from a feed:** a subscribed calendar's settings have "Hide cancelled events", which leaves events the feed marks cancelled off the calendar instead of striking them through. One the feed un-cancels comes back.
- **Edit approved senders as text:** Settings, Invitations, Approved senders, Edit as text: one per line, with `*.example.com` for a domain's subdomains. Paste a list or import a CSV; lines that can't be used are left out and listed with the reason.
- **Plugins wait for their settings:** one that needs a key or account (Fibery) opens its settings when you turn it on, says what to fill in, and doesn't run until you have.
- **Run now runs now:** Run now, saving a plugin's settings, and adding or changing a tide, sun or weather calendar start the plugin straight away, and the calendar shows the result as soon as it's done. (Where the server can't, they run within a minute as before.)
- **An open page picks up an update sooner:** it notices a new version within 30 seconds (it could take half an hour) and moves to it when you next change page, as well as when you switch away.
- **Fixes:** typing in a plugin's settings is no longer lost when Better-Cal updates itself in the background; the update now waits until nothing is unsaved. A session or API key revoked while a Fibery move or its undo was under way can no longer finish it (the 2026-10-27 security review's Low finding): the credential is checked again just before Fibery is changed and when the change is saved. The same now holds for adding, copying, editing or deleting an event on a Google calendar.
- **Library updates:** Preact 11.0.2, the Squire editor 2.4.10 and Guzzle 8.3.1, all bug fixes.

Operators: no database changes; update as usual. Reading an event page from quick add is a new outbound request, made through the same address checks as feeds ([SECURITY.md](SECURITY.md#scope-notes)).

## 1.3.0 (2026-10-26)

A Fibery plugin, tide calendars per station with full filtering, and Sun and Weather for any place.

- **Fibery plugin:** your Fibery tasks and projects on the calendar. Drag one (or use Move) and its date changes in Fibery, with Undo; changes made there arrive within a minute. [Fibery](docs/plugins/fibery.md) has the setup.
- **Tide calendars, as many as you like:** each is one NOAA station (search by name) with its own choice of tides: lows or highs, how low or high, in daylight, at times of day, on days of the week, up to a year ahead. Add one from the Tides card in Plugins. Your current station becomes the first one. [Tides](docs/plugins/tides.md) has the details.
- **Sun and Weather for any place, or for a tide station:** add a calendar per place, or add one from a tide calendar's "Linked calendars" to get its sunrise, sunset or forecast there; they follow if the station changes. Your current place carries over.
- **Plugins can offer many calendars:** each with its own settings, a status line, long searchable lists and settings that follow another calendar's place ([writing a plugin](docs/plugins/authoring.md#calendars-the-person-adds)).
- **Drag events out of a day's full list:** in a busy month day, open the full list ("+N more") and drag any event onto another day. The list steps aside while you drag and comes back afterwards, so you can move several in a row. The list is also wider (up to 520 pixels on a big screen), long titles wrap instead of being cut off, and each event shows its time inside it, as in the grid; clicking an event opens it, so the separate open buttons are gone.
- **The "Better-Cal was updated" banner is at the top,** under the toolbar and in a stronger color, with Reload right beside the message; at the bottom of a large screen it was easy to miss.
- **Questions you can't take back ask in the app:** adopting a calendar, removing a subscription or a person, sending an invitation reply again, undoing over newer changes and stopping reminders on a device now ask in a dialog with Cancel first and a button that says what it does. Deleting a folder, or an event with the Delete key, no longer asks; Undo is in the notice instead.
- **Plugins can write times back:** a plugin can let you drag, resize or Move its events and send the new time to its source, and its settings can offer choices it finds there ([writing a plugin](docs/plugins/authoring.md#rescheduling-from-the-calendar)). A plugin calendar's settings name its plugin and link to that plugin's settings.
- **Fixes:** plugin calendars no longer rewrite every event on each run (CalDAV apps were told they had all changed); a Google calendar's "Check for updates" menu offers every 5 minutes, its starting setting (it showed 15 minutes, and choosing anything slowed it down for good); a plugin's key field now has Show and a character count, so you can tell a paste landed; a plugin's Uninstall is now Delete data, since the plugin stays (untick it to pause): it asks once, in its card, with Cancel first and the choice to keep or delete its events spelled out (Cancel used to lead on to a second question), and kept calendars stay visible.

Operators: two database changes, applied automatically by `update.php` or the database update page. Existing Tides, Sun and Weather calendars keep their station or place. Tides now shows highs and lows with no shading until you set its filters.

Maintainers: a second hardware security key signs releases, and the first release listing it must be signed by a key already listed ([Signing](docs/releasing.md#signing)).

## 1.2.0 (2026-10-26)

Quick add understands a lot more, plus a few smaller features.

- **Quick add reads more on its own:** day ranges ("Workshop Mon-Fri", and "Standup Mon-Fri 9am" repeats), lengths ("Trip 2 weeks starting Aug 12"), relative dates ("in 2 weeks", "Friday after next"), alerts ("with a 1 hour reminder") and time zones ("9:40am EST"). [Quick add](docs/quick-add.md) has examples and the full list ([#33](https://github.com/Oshyan/better-cal/issues/33)).
- **Quick add markers:** `/calendar`, `#tag`, `@person`, and "quotes" for a title that's never read as a date. For example: `Dinner with @Sam Tuesday #food /friends` ([#86](https://github.com/Oshyan/better-cal/issues/86)).
- **Month views can start with this week at the top:** Settings → General → Today in month views ([#91](https://github.com/Oshyan/better-cal/issues/91)).
- **Edit away and busy times:** click one on the People page ([#159](https://github.com/Oshyan/better-cal/issues/159)).
- **Resizable sidebar:** drag its right edge on desktop; double-click it to reset ([#129](https://github.com/Oshyan/better-cal/issues/129)).
- **Update banner:** a new version shows a Reload banner, and one reload is enough on iPhone. You'll first see it on the next update.
- **Email invitations:** a change to one occurrence of a repeating invite applies to just that occurrence ([#155](https://github.com/Oshyan/better-cal/issues/155)), and import stays light when unread mail piles up ([#157](https://github.com/Oshyan/better-cal/issues/157)).
- **Chrome extension 2.1.2:** its new-event key opens only quick add, in a small window.
- **Fixes:** "Hide the sidebar while an event is open" saves again, the mini month no longer jumps to the next month a week early, and quick add handles en and em dashes and keeps a timed end on a later day.

Operators: no database change. Update Better-Cal before the extension.

## 1.1.1 (2026-10-24)

Fixes from a review of 1.1.0.

- **Quick add repeats read ordinary words as ordinary words:** after "to", only a calendar date ("to May 23") ends a series.
- **A weekday right after "to" or "at" is part of a name:** "Ferry to Friday Harbor Saturday" is on Saturday.
- **A repeat set by hand stays put,** in the editor and in quick add.
- **MCP:** `update_event`, `set_attendance` and `resolve_duplicate` are marked as not idempotent.
- **Fixes:** a monthly repeat restored from a draft saved before 1.1 keeps its day, logging out removes every unsaved draft (the installed app's too), the keyboard comes up on the first tap from more places on iPhone and iPad, and screen readers get a label on the keyboard fix's stand-in.

Operators: no database change.

## 1.1.0 (2026-10-24)

Quick add learns repeating events, plus a round of fixes from an iPhone tester.

- **Quick add makes repeating events:** "every Tuesday", "every other week", "first Monday of the month" and more, with a start ("starting Nov 3") or an end ("until Dec 15") if you like. Tap the repeat chip to change it.
- **The editor keeps repeats it can't show:** Monthly offers "on day 15" or "on the third Thursday", and any other rule is shown in words and saved as it was.
- **Closing a new event brought over from quick add offers Undo,** like anything typed in the editor.
- **The date picker opens on iPhone** in the event editor.
- **iPhone and iPad reminders:** push works once Better-Cal is on the Home Screen (iOS 16.4 or later), and Settings and the welcome now say so.
- **An unsaved event survives the installed app being closed:** it's kept for a day.
- **The keyboard comes up on the first tap on iPhone and iPad** for Search, New, the date jump, the filter and the editor's time zone.
- **Months are marked by a line, not a gray background.**
- **MCP: every tool says whether repeating it is safe,** with all four MCP hints.
- **Fixes:** the phone welcome no longer bounces after a detour to Plugins ([#164](https://github.com/Oshyan/better-cal/issues/164)), holding a multi-day event to drag it no longer selects text on iPhone, and the welcome fits on screen under Safari's toolbar.

Operators: no database change. The release zip carries Guzzle 8.3, with no change in behavior.

## 1.0.0 (2026-10-20)

1.0: Better-Cal installs on ordinary hosting and moves you over from Google Calendar at your own pace ([SECURITY.md](SECURITY.md#what-has-been-reviewed)).

- **Updates keep your own lines in `.htaccess`:** updates replace only what's between `# BEGIN Better-Cal` and `# END Better-Cal`, so a control panel's PHP version and your rules stay. If you changed Better-Cal's own lines, the update saves your file beside the new one.
- **On too old a PHP, the site says so** and says what to pick in the control panel.
- **A reminder device can't be registered twice under a different spelling,** which could get past the device limit and send reminders twice.

Operators: no database change. Updating through the file manager means picking the PHP version again after swapping folders ([Updating](docs/install.md#without-a-command-line)).

## 0.9.88 (2026-10-20)

Fixes from a re-review of 0.9.87's security work, plus two bugs found along the way.

- **Change your password in Settings, Account,** and optionally sign out every other browser, API key and CalDAV client.
- **Sign out everywhere else always finishes.**
- **One cancelled occurrence of an invitation skips just that occurrence;** the rest of the series stays.
- **CalDAV clients that search by date work again.**
- **Revoking an API key turns off the plugins it turned on or configured:** Review lists each one with **Turn back on**. A key that expires does the same.
- **Restoring an unencrypted backup asks first;** `update.php` takes `--allow-plain` for the same thing.
- **Very long comma lists in calendars are refused** ([limits](docs/limits.md#zip-imports-and-malformed-dates)).
- **Changes to existing invitations have their own Review limits:** 100 a day and 25 per sender.
- **CalDAV bounds every request body** before parsing.
- **ZIP imports count the bytes each calendar actually unpacks to.**
- **Security update notices go only to destinations the owner set,** and turning place lookup off also stops opening-hours queries.

Operators: one database change, applied automatically by `update.php` or the database update page. [Known hosting limits](docs/limits.md#known-hosting-limits) covers unusual hosting.

Maintainers: release ZIPs are opened with a consistency check, and CI's install job fails if a library its guarded tests need is missing (`BC_REQUIRE_LIBS=1`).

## 0.9.87 (2026-10-19)

Fixes from the final security scan before 1.0 (SECURITY.md lists it); the ones that change what you see come first.

- **Choose which emailed events are added automatically:** Settings offers any sender, approved senders (the default) or holding everything for Review. Adding an invitation sends a reply only with **Add and accept**.
- **Setup codes really work once.**
- **Signing out other browsers offers a subscription choice:** keep the feeds those browsers added running, or pause them for Review.
- **Agents can configure and run plugins** and decide duplicates; plugin secret fields are write-only.
- **Copy to calendar uses the destination just selected.**
- **Malformed dates skip one event, not a whole feed.**
- **A place search service that came back stops showing as failing.**
- **Reminder devices have an enrollment limit** of 100 by default; remove old devices in Settings, Notifications before adding more.
- **Private deep links use fragments:** new webcal handlers and Chrome extension 2.1.1 keep feed secrets and event details out of the first request.
- **Unreadable email stays visible in Review** until dismissed.
- **Google grants that are no longer accepted say Reconnect needed** in Settings.
- **Exports stay complete** without holding the whole calendar in memory, one at a time.
- **Backups refuse unsafe storage and missing encryption settings;** Settings, System supplies a settings line to paste.
- **A stopped restore keeps the site closed** and offers **Run the restore again**.
- **Calendar uploads are checked before expensive parsing.**
- **Outside calls respect persistent limits.**
- **Revocation is checked again when changes are saved or messages sent.**
- **A restore cannot race the worker.**
- **Release verification checks intent and library source.**
- **Unsigned release notes cannot control updater terminal output.**
- **Fixes:** large trips use less memory and history storage, and private storage works under account-group-writable home and app folders.

Operators: two database changes, applied automatically by `update.php` or the database update page. Emailed events now come in only from approved senders by default; recent senders are approved for you. If you use the Chrome extension, update it to 2.1.1 after this update, not before.

Maintainers: prepare the signing environment once with `bash scripts/release-signer.sh prepare`. Deployment configuration now comes only from `scripts/deploy.env`; run `chmod 600 scripts/deploy.env`. `SKIP_BACKUP=1` now requires typing `skip backup` at an interactive terminal.

## 0.9.86 (2026-10-16)

From a review of everything since installing and updating moved into the app.

- **A release is signed only after it's checked** against a rebuild from its tag, with a hardware security key.
- **The update notice only believes signed releases.**
- **Passwords with letters like Å, ą or х work in the settings file.**
- **A stranger can't keep you out of setup:** wrong tries now just wait a second.
- **Updates that stop partway can always be undone** with `update.php --rollback`.
- **The database update page recovers too** if the host cuts it off.
- **Old copies and backups are pruned:** the newest two previous versions and database backups stay. Copies you made yourself are never touched.
- **No old code for a moment after an update.**
- **Fixes:** `update.php` says when a release changes the signing keys and warns when a rollback goes below the security floor, a settings file reached through a symbolic link is never replaced by a copy, backups are private from the moment they're created, a hand-made `.env` converted by the web server becomes owner-only, and the private-files check names the file that leaked.

Operators: no migration. Maintainers: `scripts/release.sh prepare` now needs the hardware security key, or `--recovery` with the key file.

## 0.9.85 (2026-10-16)

- **A stopped worker is hard to miss:** its banner sits at the top of the page, and a red dot on Settings and the System tab stays until it runs again.
- **"What to do" says what to do:** it opens Settings, System at the cron job, with how to fix it.
- **The worker is optional for plain calendar use, and says so.** "I turned it off on purpose" stops the warnings.
- **Place search can be turned off:** `BETTERCAL_PLACE_SEARCH=off` in the settings file.
- **Export everything:** Settings, Account downloads a zip with one `.ics` file per calendar. Each calendar's settings also have **Download .ics**.
- **Import into a calendar you already have** with Import's new "Into" choice.
- **Settings, System says what's off** ("off", "not set up") instead of "ok", and has room to read.
- **Fixes:** stepping through an event's day finds all of it.
- [Installing](docs/install.md#removing-better-cal) has a new section on removing Better-Cal completely.

Operators: no migration.

## 0.9.84 (2026-10-15)

- **The release zip has its files at the top,** with no `better-cal` folder inside, so you extract straight into your `better-cal` folder. [Installing](docs/install.md#install) has the steps.
- **Time zones are searchable** by city, country, region or offset.
- **Fixes:** the welcome comes back on the step you left, its link to the Google setup guide looks like the buttons beside it, and Plugins no longer flashes a loading placeholder.

Operators: no migration. `php server/bin/update.php` from 0.9.83 handles the new zip layout. From 0.9.82 or older, it stops before changing anything ("unexpected path"); update by hand once ([Updating](docs/install.md#updating)), or to 0.9.83 first.

## 0.9.83 (2026-10-15)

Your private files now stay private on any host ([#142](https://github.com/Oshyan/better-cal/issues/142)); recommended for every install.

- **The settings file is now `.env.php`:** same `KEY=VALUE` lines, behind a first line that keeps it private. Keep that line when you edit the file. Better-Cal converts `.env` on its own, or `php server/bin/settings.php --convert` does it by hand.
- **Backups are encrypted** with a key made from your session secret. `php server/bin/backup.php --decrypt FILE OUT.sql.gz` gives a plain copy. Keep a copy of `.env.php` with any backup you store elsewhere.
- **The setup code, the update record and PHP's error log are guarded the same way;** the code file is now `better-cal-code.php`.
- **Settings, System has a Private files row** that says whether your settings could be downloaded.
- **The worker's cron line no longer writes a log into the app folder:** it's now `... worker.php >/dev/null 2>&1`.
- **Better-Cal notices when its cron job stops** for 15 minutes, with a banner and one email where email is set up.
- **The cron command follows your server's PHP where it can.**
- **Rolling back to a version before 0.9.83** writes `.env` back for it.
- **The database update page can restore the database** from the backup it just made, if the update fails.
- **`update.php` also reads release zips with their files at the top,** the layout releases use from 0.9.84 on.
- **Fixes:** turning on notifications right after setup works, and the setup check for a missing `sodium` extension says what Better-Cal uses it for.

Operators: no migration. Update with `php server/bin/update.php`. Where `.env` belongs to another account (root, as the deploy script sets it up), run `sudo php server/bin/settings.php --convert` once; the deploy scripts refuse to run with both files present. Change the worker's cron line to end in `>/dev/null 2>&1` if it writes `worker.log` inside the app folder.

## 0.9.82 (2026-10-15)

Security fix, recommended for every install whose `better-cal` folder sits inside a web root.

- **Only Better-Cal's two front doors run from the web:** every PHP file except `server/public/index.php` and `dav.php` answers 404 over the web, whatever the server's settings ([#142](https://github.com/Oshyan/better-cal/issues/142)).

Operators: no migration. Update with `php server/bin/update.php`. Installs whose `better-cal` folder is outside any web root, as the install guide recommends, were not exposed.

## 0.9.81 (2026-10-15)

- **The app folder refuses to be served, except for `server/public`,** for hosts that put it inside the main site's web root. [Choosing where to put it](docs/install.md#choosing-where-to-put-it) covers this layout.

Operators: no migration. Apache and LiteSpeed read the new `.htaccess`; Nginx ignores it, so with Nginx keep the folder out of any web root, as before.

## 0.9.80 (2026-10-15)

- **The address check compares host names only, not ports,** so an install on a non-standard port isn't redirected to itself. A trailing dot counts as the same name.

Operators: no migration. Everything listed under 0.9.79 ships here.

## 0.9.79 (2026-10-15)

*Not published; shipped in 0.9.80.*

- **Better-Cal answers only at its own address:** requests for any host but the one in `BETTERCAL_BASE_URL` are redirected there, or refused. `BETTERCAL_ALLOWED_HOSTS` lists extra names.
- **The install guide says where to put it, and why.** [Choosing where to put it](docs/install.md#choosing-where-to-put-it).
- **The setup and database update code is replaced after 5 wrong tries,** not 10.

Operators: no migration. If a proxy in front of Better-Cal rewrites the `Host` header, or you reach one install by two names, add the others to `BETTERCAL_ALLOWED_HOSTS` before updating.

## 0.9.78 (2026-10-15)

- **The "Database update needed" and "Updating" screens actually show in the app.**

Operators: no migration.

## 0.9.77 (2026-10-15)

- **Set up Better-Cal in the browser, no command line needed:** upload the release zip, point your domain at it and open the address, then enter the code from `better-cal-code.txt`. [Install without a command line](docs/install.md#install-without-a-command-line).
- **Update without a command line:** unzip the new release beside the old one, copy `.env` across and swap the folder names; the update page applies any database changes. [Updating without a command line](docs/install.md#without-a-command-line).
- **While database changes are waiting,** the app shows that, the API and CalDAV refuse, and the worker pauses.

Operators: no migration. `php server/bin/update.php` still updates in one step.

## 0.9.76 (2026-10-14)

- **Import your whole Google Calendar export in one step:** **+ Import calendars** in the sidebar takes the zip from Google Calendar's export or Takeout, each calendar as its own. `server/bin/import-takeout.php` takes the zip too, for exports too large to upload.
- **Vi-style navigation:** h and l move back and forward a day, k and j a week.
- **Fixes:** importing from Google no longer names a calendar "Book club_c" when its Google id starts with `c_`.

Operators: no migration. Update with `php server/bin/update.php`.

## 0.9.75 (2026-10-14)

- **Releases are signed, and the updater checks:** `php server/bin/update.php` installs nothing unless the signature verifies against the signing keys Better-Cal ships with. [Releasing](docs/releasing.md#signing) explains it.
- **The updater waits for a running worker job** before backing up.
- **Backups and the previous version are kept out of the web's reach,** and the updater warns when the app looks like it sits inside a web root.

Operators: no migration. Update with `php server/bin/update.php`; from this release on, it checks each release's signature.

## 0.9.74 (2026-10-14)

- **One-command install:** unpack the release zip (no Composer needed), create a database and run `php server/bin/install.php`. See [Installing](docs/install.md#install).
- **One-command update:** `php server/bin/update.php` backs up the database and installs the newest release; `--rollback` puts both back. See [Updating](docs/install.md#updating).
- **Maintenance mode:** an empty `.maintenance` file in the app directory makes the site answer "updating" and pauses the worker.
- **Fixes:** the welcome's Import row points at Google Calendar's own export instead of Takeout.

Maintainers: `web/vendor/README.md` documents the vendored browser libraries, and `node scripts/vendor.mjs --check-upstream` checks them.

Operators: no migration. `update.php` needs to create folders beside the app directory, or a git checkout with Composer.

## 0.9.73 (2026-10-13)

- **The event panel's header shows the year** when it isn't this one.

Operators: no migration.

## 0.9.72 (2026-10-13)

- **Past events aren't looked up for the map any more,** only upcoming ones. Settings → Location & maps → "Look up addresses for past events too" brings them back.
- **An event from another year shows the year** in the event panel, hover card and Review.
- **Fixes:** Activity's duplicates line counts copies and names the events, and opening an event on a hidden calendar from Activity no longer leaves a blank panel.

Operators: no migration.

## 0.9.71 (2026-10-13)

- **An open repeating event stays open after a "this and following" change.**

Operators: no migration.

## 0.9.70 (2026-10-12)

- **Fixes:** Option/Alt-drag copying works, and dropping an event onto a calendar or person in the sidebar works again.

Operators: no migration. 0.9.69 was never published; its changes ship here.

## 0.9.69 (2026-10-12)

*Not published; shipped in 0.9.70.*

- **Duplicate an event:** More → Duplicate copies it to the same calendar and opens the editor so you type the new day.
- **Option/Alt-drag copies an event** instead of moving it, in week, day and month views or onto a sidebar calendar.
- **Remove an event when you accept its cancellation:** Settings → General → Invitations (with Undo).
- **Fixes:** DOMPurify 3.4.17, which cleans up event descriptions.

Operators: no migration.

## 0.9.68 (2026-10-12)

- **A Google-synced invitation shows as Planned or Maybe by your answer:** Accept is Planned; anything else is a Maybe. Choosing by hand still wins until your next answer.
- **Fixes:** a calendar whose Google account was disconnected says so, and the password prompt reads properly.

Operators: no migration.

## 0.9.67 (2026-10-12)

- **Declining an invitation makes it a Maybe** instead of Planned. Changing it by hand sends nothing to the organizer.
- **Dismiss an unanswered invitation from Review:** nothing is sent, and an updated invitation asks again.

Operators: no migration.

## 0.9.66 (2026-10-12)

- **An open app keeps up with background changes** without reloading or changing anything under a drag: plugin calendars, AI filter verdicts, warnings, duplicates, settings from another device, the Review badge and Activity.
- **Plugin calendar changes reach CalDAV:** phones and desktop apps now see them.

Operators: no migration.

## 0.9.65 (2026-10-12)

- **Fixes:** accepting an emailed change or cancellation works again.

Operators: no migration.

## 0.9.64 (2026-10-12)

- **Invitation replies are laid out the way Google's own are,** so Gmail no longer shows the organizer "Unable to load event".
- **The same answer can't be sent twice:** pressing Accept again does nothing; a different answer still sends.

Operators: no migration.

## 0.9.63 (2026-10-12)

- **Accepting reaches the organizer when you invited yourself:** replies go from the server address that was actually invited, never the organizer's.
- **Add and accept:** a new emailed invitation in Review can be added and accepted in one step.
- **An invitation you haven't answered is a Maybe** until you accept it.
- **The Review badge keeps up while the app is open,** within 30 seconds.
- **A reminder whose time passed before the event was added now fires, late:** up to six days late, while the event is still ahead.
- **Fixes:** invitation changes say which version they are ("Updated invitation (v2)"), and Connections calls a calendar made in the account "Account".

Operators: no migration.

## 0.9.62 (2026-10-12)

- **Google calendars carry Google's own G, in color,** in the sidebar and a calendar's settings.
- **Connections names each calendar's type:** Owned, Shared, Feed copy or From Google.

Operators: no migration.

## 0.9.61 (2026-10-12)

- **Fixes:** a calendar's Source reads as one sentence again.

Operators: no migration.

## 0.9.60 (2026-10-12)

- **See which calendars come from somewhere else:** the sidebar marks Google calendars and web subscriptions, and a calendar's settings say where it comes from.
- **Settings → Connections is clearer:** each Google account lists every calendar it can see, with an "In Better-Cal" column.
- **The finished Move to Google message says where the calendar now lives** and how to share it.

Operators: no migration.

## 0.9.59 (2026-10-12)

- **Fixes:** an edit to a Google calendar no longer flips back for a few minutes during a sync, the move dialog shows progress right away, and on a phone it fills the screen.

Operators: no migration.

## 0.9.58 (2026-10-12)

- **Moving a calendar to Google has its own dialog:** "Move to Google…" (a full-screen sheet on a phone).
- **Granting Google's create-calendars permission comes back to the move.**
- **Fixes:** disabled buttons look disabled everywhere, and Settings → System forgets deleted feeds and push devices.

Operators: no migration.

## 0.9.57 (2026-10-10)

- **Fixes:** the day arrows on a phone's event sheet no longer keep a grey box after a tap, and a swipe flashes the matching arrow.

Maintainers: Guzzle 8.2 (from 7.15), no change in behavior. sabre/vobject 5 waits for sabre/dav ([#128](https://github.com/Oshyan/better-cal/issues/128)).

Operators: no migration. `composer install` brings the new Guzzle in, as deploys already do.

## 0.9.56 (2026-10-09)

- **Back on a phone closes what's open** (sheets, quick add, jump to date, the day list) instead of leaving the app, and steps the event sheet down reliably.
- **A turned phone keeps the event in view** beside a narrowed calendar. ([#125](https://github.com/Oshyan/better-cal/issues/125))

Maintainers: the weekly dependency check works.

Operators: no migration.

## 0.9.55 (2026-10-09)

- **Stepping through a day skips duplicates.** ([#27](https://github.com/Oshyan/better-cal/issues/27))
- **The hover card names the plain-language filter** that dimmed or highlighted an event.
- **The Filters page notes a broad AI filter** that judges every event on every subscribed calendar.
- **Fixes:** hover card dates for all-day events west of UTC, context hover cards leave off the reasons list, and the overview says "5 day" on a turned phone.

Operators: no migration.

## 0.9.54 (2026-10-09)

- **A phone turned sideways keeps the phone layout,** with a slimmer bottom bar, sheets from the right, and five days a row in the overview and week view. ([#125](https://github.com/Oshyan/better-cal/issues/125))
- **Context items show the hover card,** like weather, sunrise and sunset, a holiday or a "+N".

Operators: no migration.

## 0.9.53 (2026-10-09)

- **Preact 11:** the UI framework moves from 10.29.7 to 11.0.1. Nothing should look or work differently; if something does, that's a bug worth reporting.

Operators: no migration, and no web server MIME change is needed.

## 0.9.52 (2026-10-09)

- **Fixes:** Settings → Location & maps shows the "Place search service" row again.

Operators: no migration.

## 0.9.51 (2026-10-09)

- **Place search outages only interrupt when they matter:** with more than one service, you hear only when all are down. When Photon alone is down, you're pointed to free LocationIQ or MapTiler keys.
- **Security record:** `SECURITY.md` and the README include the 2026-10-06 scan (77 findings, fixed through 0.9.42).

Operators: no migration.

## 0.9.50 (2026-10-09)

- **Fixes:** with MapTiler in the list, a bare house number finds the houses near you again.

Operators: no migration.

## 0.9.49 (2026-10-09)

- **MapTiler place search:** fast addresses from a bare house number, and its terms allow keeping the places you pick.
- **More than one service, in order:** the place search setting takes a list, Photon always last. [Geocoding and place search](docs/geocoding.md) has the setup.
- **Events you didn't place by hand land faster and in the right country,** using your search services before Photon.
- **Fixes:** a town comes first when you type its name, and venues whose names start with a number are found by every service.

Operators: no migration. `BETTERCAL_PLACE_SEARCH` takes a comma list from `locationiq`, `maptiler` and `stadia`. New: `BETTERCAL_PLACE_LOOKUP` (for placing events; empty means the search list) and `BETTERCAL_MAPTILER_GEOCODING_KEY` (a key locked to the site's address is refused).

## 0.9.48 (2026-10-09)

- **Fixes:** a picked house address no longer repeats its street.

Operators: no migration.

## 0.9.47 (2026-10-09)

- **A picked place keeps its address,** which the event panel, hover card, agenda and your other calendar apps show.
- **The location box shows whether it's placed:** a solid pin once picked, an outline for typed text, and an x to clear.
- **Typing in the editor keeps the day you chose** unless the text names a date.
- **Fixes:** place search gives up after three seconds at most when Photon is slow.

Operators: no migration.

## 0.9.46 (2026-10-09)

- **Faster place search:** a free LocationIQ key answers in about a tenth of a second and finds venues, world cities and airports more reliably. Stadia Maps works too; Photon stays the default, no key. [Geocoding and place search](docs/geocoding.md) compares them.
- **The dropdown credits whoever answered.**
- **Each event knows where its coordinates came from.**
- **Fixes:** the filter box no longer leaves gaps (#126), and the test email's button says Open Better-Cal.

Maintainers: `scripts/push-settings.sh` changes a server's `.env` from a local, gitignored `scripts/server.env`.

Operators: migration 048. New optional settings: `BETTERCAL_PLACE_SEARCH` (`photon`, `locationiq` or `stadia`), `BETTERCAL_LOCATIONIQ_KEY` and `BETTERCAL_STADIA_KEY` (see `.env.example`). A LocationIQ key restricted by address needs the server's IPv4 address.

## 0.9.45 (2026-10-09)

- **A filtered day shows its matches first,** and "+N" counts matches that didn't fit.
- **Typing a city's name offers the city** first.
- **Reconnecting a Google account brings its calendars back:** Re-attach restores each with its events instead of making a duplicate.
- **Reminder times follow your clock setting.**
- **Fixes:** PHPMailer 7 and web-push 11, with no change in behavior.

Operators: no migration. `composer install` brings in the new versions.

## 0.9.44 (2026-10-09)

- **Addresses find the one near you:** a bare house number or a half-typed street lists nearby matches first.
- **Famous places beat their replicas:** "Eiffel Tower" finds the one in Paris.
- **Each calendar chooses its reminders** in its settings: your defaults, none, or its own. Google calendars of your own now use your defaults too.
- **Calendar tags are gone;** nothing used them. Event tags are unchanged.
- **Fixes:** update check failures clear when checks work again.

Operators: migration 047 drops the unused `calendar_tags` table when empty. The calendar API no longer accepts or returns `tagNames`.

## 0.9.43 (2026-10-09)

- **Update checks work.**
- **The app starts from its offline cache,** and a failed start shows Try again instead of a blank page.
- **Place search looks near you first** (about 300 km) before going worldwide.
- **Hover any event for a card** with its full title, when, calendar, place and why it looks the way it does.
- **A maybe has a dashed outline** and no longer takes the calendar's color for its title.
- **A new keyword filter matches titles** unless you tick more fields.
- **Fixes:** an open event keeps its description and time zone when something changes.

Maintainers: CI fails a piped test step, and `scripts/deploy.sh` tests the exact commit being shipped.

Operators: no migration.

## 0.9.42 (2026-10-08)

- **Offline calendar data is bounded and read-only:** it expires within 24 hours, excludes credentials and private feed addresses, and is invalidated on logout.
- **Update notices:** all, security-only or none, plus a manual check in Settings. Release metadata is verified.
- **Reviewable dependencies and releases:** DOMPurify 3.4.16 closes its published advisories, with Squire 2.4.9.
- **Narrower trust boundaries:** remote MCP requires HTTPS, authenticated pages can't be framed, logout clears private context, and login passwords start masked.

Operators: migration 046, no configuration required. The worker checks GitHub for releases daily. Offline calendars stay viewable up to 24 hours but can't be edited until the server is reachable.

## 0.9.41 (2026-10-08)

Maintainers: a Linux CI validation fix for 0.9.40, no change in application behavior.

Operators: no migration and no configuration change.

## 0.9.40 (2026-10-08)

- **Feed addresses and tokens are encrypted at rest,** and Undo history no longer keeps usable copies.
- **Development clones are sanitized:** production authentication, Google connections, push devices, outbound feeds, jobs and plugin state never enter the dev database.
- **Development isolation is secure by default:** `DEV_ISOLATION_MODE=strict` requires separate identities; `shared` mode permits reuse with a warning.
- **Narrower deploy and helper boundaries:** removed plugins are quarantined, private destinations stay blocked, and account seeding no longer accepts passwords in process arguments.

Operators: migration 045 needs one quiesced rollout: run the first deployment with `FEED_CREDENTIALS_QUIESCED=1`. Dev flags are in `docs/install.md` and `scripts/deploy.env.example`; reconnect integrations in a dev clone by hand.

## 0.9.39 (2026-10-08)

- **Outside actions share limits:** reminder, notification and test email have persistent budgets, plugin jobs are rate-limited, and core calendar work comes first.
- **Owner decisions need a browser session:** API tokens and MCP can inspect Review but can't accept, dismiss, RSVP, decide duplicates or proposals, or manage plugins.
- **Calendar edits respect their source:** a trip can't bulk-move externally managed members, and different-UID external matches wait for your confirmation.

Operators: migration 044 cancels pending plugin jobs and reopens unconfirmed external links for Review. Review the enabled-plugin list once after upgrading. Excess scheduled email is dropped with a System notice. Limits are in `.env.example` and `docs/limits.md`.

## 0.9.38 (2026-10-08)

- **Dense calendar data can't multiply into unbounded work** on the server or in the browser.
- **Bulk and public data paths have total limits:** published feeds, Takeout migrations and plugin snapshots.
- **Share handoff is bounded:** cross-site requests are rejected and sizes are capped.

Operators: no migration. Exceptionally dense views ask for narrower dates or calendars, oversized published feeds return `413`, and limits can be tuned (see `.env.example` and `docs/limits.md`).

## 0.9.37 (2026-10-08)

- **Untrusted calendar data is cleaned first:** links open only for web URLs, meeting links only on the provider's real HTTPS host, and descriptions stay inert.
- **Malformed calendars can't cause runaway work:** repeat rules have work budgets, and incomplete feed downloads keep the last good copy.

Operators: migration 043 cleans unsafe legacy fields without deleting events. Exceptionally complex repeat rules may become non-recurring.

## 0.9.36 (2026-10-07)

- **Duplicate detection stays bounded on dense calendars:** a sustained backlog shows in System Health.
- **Fixes:** a duplicate group still shows as one event when its connecting copy is off screen.

Operators: no migration.

## 0.9.35 (2026-10-07)

- **Fixes:** migration 042 no longer rebuilds the Google-move table, which MySQL 8.4 could reject.

Operators: this fixes the migration failure possible in 0.9.33–0.9.34. Rerunning 0.9.35 completes an interrupted deployment.

## 0.9.34 (2026-10-07)

- **Fixes:** the one-time migration-042 deploy can pause and restore worker scheduling again.

Operators: to finish an interrupted 0.9.33 upgrade, rerun the 0.9.34 deploy with `MOVE_INTEGRITY_QUIESCED=1`.

## 0.9.33 (2026-10-07)

- **Moving a local calendar to Google is one coherent operation:** the calendar is briefly read-only; if an upload stops partway, choose **Try again** or **Stop move and keep local**.
- **Interrupted Google responses can't create duplicate calendars or events.**

Operators: migration 042. The first upgrade of an existing install needs `MOVE_INTEGRITY_QUIESCED=1` and `PHP_FPM_SERVICE` in the ignored deployment configuration; `scripts/deploy.sh` then pauses traffic, migrates and resumes it. Unfinished moves are stopped for review.

## 0.9.32 (2026-10-07)

- **API keys no longer inherit browser-only access** to the Google calendar list, other credentials' subscription addresses, or feeds they didn't create.
- **Creating an API key needs a password confirmed in the last ten minutes.**

Operators: no migration. API clients that relied on that access must use a browser session or the credential that created the resource.

## 0.9.31 (2026-10-07)

- **Plugin proposals show which local calendar each item uses,** and a changed plan needs a fresh review.
- **Accepting a proposal and its Undo are all-or-nothing.**
- **Proposals write only to local calendars.**

Operators: no migration. API and MCP clients must echo the proposal's `reviewToken` when accepting or dismissing it.

## 0.9.30 (2026-10-07)

- **A first-time emailed invitation waits in Review:** choose **Add to calendar** to create it. Bookings and tickets still land automatically.
- **No real personal data in the code, tests or docs:** **visit-intents** starts with an empty wishlist, and **tides** has no default station.

Operators: no migration. If you use tides, set a station in its settings.

## 0.9.29 (2026-10-07)

- **A broken or huge Google calendar can't monopolize the worker:** polls have size, time and memory limits, and a paused calendar says why.

Operators: no database migration. `BETTERCAL_LIMIT_GOOGLE_*` settings tune the limits within documented hard ceilings.

## 0.9.28 (2026-10-07)

- **Quick add can't make unbounded paid model calls:** when capacity is full, the regular parser still works and the draft says AI parsing is paused.
- **Busy subscribed feeds can't monopolize model processing:** delayed work retries, and Review shows one notice.

Operators: migration 041. `BETTERCAL_LIMIT_MODEL_*` settings tune the limits within documented hard ceilings.

## 0.9.27 (2026-10-07)

- **Email can't create unbounded calendar data or paid model calls:** iCalendar, schema.org and Google-template extraction keep working when model capacity is full.
- **Oversized mail is rejected early.**
- **Refusals show as one Email automation notice in Review,** and the email stays in the mailbox.

Operators: migration 040. `BETTERCAL_LIMIT_MAIL_*` settings tune the limits within documented hard ceilings.

## 0.9.26 (2026-10-07)

- **Oversized JSON requests are refused early:** 1 MiB by default; calendar-file imports keep their 25 MiB.
- **Requests to outside services identify Better-Cal** with the project URL.

Operators: no database migration. `BETTERCAL_LIMIT_JSON_BODY_BYTES` raises the JSON limit, up to 4 MiB.

## 0.9.25 (2026-10-07)

- **Production backups no longer trust application-controlled paths,** and deploys no longer use root inside the application tree.

Operators: no database migration. `BACKUP_DIR` defaults to `/home/bettercal-backups` and must be a root-owned mode-`0700` direct child of `/home` or `/var/backups`. Existing backups aren't moved: confirm one new backup and its off-host coverage before archiving the legacy directory. The backup helper refuses SQLite.

## 0.9.24 (2026-10-06)

- **The month view's day-expand button says what it does:** "Show all of this day's events in full".

## 0.9.23 (2026-10-06)

- **Signing out stops reminders on that device;** signing back in restores them.
- **API keys can't inspect or disable browser reminder devices.**
- **System health no longer returns raw push-service endpoints.**

No database migration is required.

## 0.9.22 (2026-10-06)

- **Hosts without `proc_open` work again,** broken since 0.9.17.

## 0.9.21 (2026-10-06)

- **Every open device refreshes after a change made anywhere,** within 30 seconds.
- **Plugin calendars don't get your default reminders.**
- **A Google calendar you add starts with the right role:** yours as Mine, holidays and birthdays as Context, shared ones as Opportunities.
- **A feed you publish keeps the events nearest today** when over its 5,000-event cap; `BETTERCAL_LIMIT_OUTFEED_EVENTS` raises it.
- **Paused calendar subscriptions wait in Review** with **Keep updating**.
- **Fixes:** clicking a context item while an event is open opens it on the first click, disconnecting Google warns when it would stop a move, and the `o` shortcut is gone.

Operators: migration 039. `scripts/deploy.sh` now ships the last commit, not the working tree: commit before deploying.

## 0.9.20 (2026-10-06)

- **Undo right after creating something undoes that creation.**

## 0.9.19 (2026-10-06)

- **A calendar moved to Google is checked every five minutes.**
- **CalDAV refuses edits to plugin calendars.**
- **Fixes:** the "Google account disconnected" error says what actually works.

Operators: migration 038 runs on deploy.

## 0.9.18 (2026-10-06)

- **A repeating event with too many occurrences to show says so,** and the events API lists it in a new `capped` field.
- **[Scale and limits](docs/limits.md)** says who Better-Cal is sized for and which limits you might run into.

## 0.9.17 (2026-10-06)

- **Exception-heavy repeating calendars fail safely:** a broken series is rejected with an actionable error, and the last good subscribed copy stays visible.
- **Sign out everywhere else** also gives signed-in public calendar feeds new URLs.
- **Calendar subscriptions created by an API key stop with that key:** keep, adopt or delete them in calendar settings.
- **A compromise reset stops Google too:** `server/bin/seed.php --revoke-tokens` pauses connected accounts and revokes refresh tokens. Reconnect under Settings, Connections.
- **Sensitive Google setup requires a recent password.**
- **Google consent belongs to one browser session.**
- **Location search follows the same outbound-network safety rules as calendar subscriptions.**
- **Fixes:** far-dated event links load without paging through every date in between.

Operators: migrations 036 and 037; existing ICS subscriptions pause once for review.

## 0.9.16 (2026-10-06)

- **All-day events are stored one way,** so editing a series over CalDAV no longer drops its edited days' tags, people and reminders.
- **Search's "upcoming" uses your Home clock for all-day events.**

Operators: migration 035 runs on deploy.

## 0.9.15 (2026-10-06)

A second look at time zones, checked against independent calendar engines.

- **Editing "All events" from a later occurrence no longer restarts the series there.**
- **Skipped and edited days move with their series.**
- **A weekly series starts on a day it repeats on.**
- **Fixes:** the repeated hour when clocks go back, older events in Thunderbird and Outlook, dragging an event that keeps its own zone, and meeting link detection in push notifications.

Maintainers: `tools/tz-harness` runs the full cross-check against the independent engines.

## 0.9.14 (2026-10-06)

Time zone fixes from a clean install and an audit.

- **Repeating all-day events stay on their days** west of UTC.
- **Feeds and CalDAV send timed events in their own zone.**
- **Imported times keep their zone,** including Outlook's zone names.
- **Thumbs up and down show your choice** (#107).
- **Month view fits the day's context to the width** (#108).
- **Sample data for a first look:** `php server/bin/demo-seed.php` (docs/install.md).
- **Fixes:** repeat end dates, evening reminders for imported all-day events, trips moved across a DST change, "this and following" on counted series, several smaller zone fixes, and signing in loads everything a page load does.

## 0.9.13 (2026-10-05)

- **Fixes from a clean install (#79):** the welcome fills in your first calendar's name, and the sidebar month follows a new week start right away.

## 0.9.12 (2026-10-04)

- **The app's "New event" shortcut uses the window you have open.** Chrome may take a relaunch or up to a day to pick this up.

## 0.9.11 (2026-10-04)

- **New event from the app icon:** long-press it on Android, or right-click it in the Windows taskbar or Mac dock. `/new` opens quick add too.
- **Chrome extension 2.1.0: an optional new event key,** set in Chrome's shortcut settings. No new permissions.

## 0.9.10 (2026-10-04)

- **"Also on" takes one line.**

## 0.9.9 (2026-10-04)

- **Fixes:** repeating events with skipped dates no longer "change" on every poll.

## 0.9.8 (2026-10-04)

- **Only clean HTML is stored (#58 follow-up):** every way in cleans descriptions, and a one-time migration cleans what's stored.

Maintainers: migrations can now be PHP as well as SQL.

## 0.9.7 (2026-10-04)

- **Descriptions leave Better-Cal inert (#58, security review D-02)** in every export, and descriptions from CalDAV clients are cleaned when they arrive.

## 0.9.6 (2026-10-04)

- **The same event, shown once (#9):** an event that reaches Better-Cal by two routes shows once, with "Also on <calendar>"; near-matches go to Review as "Possible duplicate". Nothing is merged or deleted.
- **Activity filters** now filter, plus a new Duplicates filter.

## 0.9.5 (2026-10-04)

- **Answering invitations, finished (#70):** bookings read "Booking via <site>", invitations that can't be answered say why, and a failed reply offers Retry.
- **Invitations from Google** can be answered here, and Google tells the organizer.

## 0.9.4 (2026-10-04)

- **Move a calendar to Google (#55):** its settings, **Move to Google…**. Events keep their tags, people and reminders; "Adopt as local calendar" brings it back. A two-way bridge is recorded separately (#83).
- **Better-Cal asks Google for one more, narrow permission** (`calendar.app.created`); earlier accounts reconnect once.
- **Event links survive Google.**

## 0.9.3 (2026-10-04)

- **The Google connector's setup guide links straight to each page it needs** in Google Cloud (docs/google-calendar.md).
- **Settings, Connections and the welcome link to that guide** where Google isn't set up.

## 0.9.2 (2026-10-04)

- **A first-run welcome (#79):** two skippable steps for a new account, the basics and where to start. Settings, General has "Show the welcome again".
- **Fixes:** 3 weeks, 2 weeks and Split can be the default view.

## 0.9.1 (2026-10-04)

- **Reminders say the time on your clock:** they use the zone your device last reported (then Home) and follow the 12- or 24-hour setting.
- **The app keeps track of where your device is while it stays open,** so reminders follow you on a trip.
- **A reload puts you back exactly where you were,** open event, search or Settings section included.
- **Settings → System lists why the app last started on this device.**

## 0.9.0 (2026-10-03)

- **Better-Cal now needs PHP 8.4 (8.4.1 or later).** The install guide shows how to add it on Ubuntu 24.04 or Debian 12.

Maintainers: the GitHub check installs and tests on PHP 8.4.

## 0.8.3 (2026-10-03)

Installing on someone else's server (#22).

- **MariaDB is supported** (10.6 and later).
- **PHP 8.3 installs work again.**
- **The install guide lists every PHP extension needed,** and its Apache setup is tested.
- **Apache and shared hosting need no web-server setup:** `server/public/.htaccess` ships with the routing and the Authorization-header pass-through. A host only has to allow `FileInfo` overrides.
- **Fixes:** `.env.example` includes the Google connector's two keys.

## 0.8.2 (2026-10-03)

- **The phone drawer's pages moved to the bottom,** under a Manage heading, so the calendars come first.

## 0.8.1 (2026-10-03)

- **A person's away and busy times are called that,** or their availability, everywhere they were "spans", and "Open person" replaces "Open in People".

## 0.8.0 (2026-10-03)

The sidebar and Settings on phones.

- **The sidebar's pages are a short grid at the top** of the phone drawer, now titled Better-Cal.
- **Each calendar is listed once on a phone,** in its folder or under Other calendars.
- **Only and a calendar's settings are a press and hold away on a phone:** hold a calendar to show only it or open its settings, or a person to show only their availability or open them. Desktop is unchanged.
- **Settings on a phone opens on a list of its sections.** A desktop keeps its tabs.

## 0.7.4 (2026-10-03)

- **Fixes:** stepping from a multi-day event walks the day you opened it on, in every view, and Past search results show a running series at its latest date that has gone by.

## 0.7.3 (2026-10-03)

Search, and the leftovers from the phone work.

- **Search finds upcoming events first, and finds all of them** ([#60](https://github.com/Oshyan/better-cal/issues/60)): pick Upcoming (the default), All or Past under the search box.
- **Back from an event opened in search returns to the results.**
- **Quick add uses the editor's date and time boxes:** type "fri" or "7p", or pick a quarter hour from the list.
- **Leaving the editor without saving returns to the event** it was opened from.
- **Fixes:** a repeating series shows its next date in search results, and no result looks chosen until you choose it.

## 0.7.2 (2026-10-03)

- **The old full-page event view is gone:** every event and trip opens in the side panel or the sheet.

## 0.7.1 (2026-10-03)

- **Fixes:** a trip's events show their map numbers only when the map is in view.

## 0.7.0 (2026-10-03)

Trips, in step with events.

- **A trip opens where an event does:** the side panel on a desktop, the sheet on a phone, with the same toolbar, Back and stepping.
- **It leads with the trip:** its dates, place, events and a map.
- **Opening one of its events keeps the way back:** "‹ Trip name" above the event, and Back on a phone.
- **Removing an event from a trip asks first,** from that event's ⋯.
- **Adding events offers yours:** your planned and maybe events during the trip.

## 0.6.10 (2026-10-03)

- **Past search results recede** the way past events do on the calendar.
- **Fixes:** More on the desktop event panel draws over the map, and stepping through a day keeps the event you opened.

## 0.6.9 (2026-10-03)

Search, the sidebar and Settings on phones.

- **Search is a full screen on a phone,** each result two lines: the title, then when and where.
- **An event opens in the event sheet everywhere on a phone,** and in the side panel on a desktop.
- **Back works everywhere on a phone,** returning to the calendar instead of leaving the app.
- **The sidebar drawer is wider on a phone,** so calendar names fit.
- **Settings on a phone:** every tab in view and thumb-sized controls.

## 0.6.8 (2026-10-03)

- **Settings labels read as headings on a phone,** and are bolder on a desktop too.

## 0.6.7 (2026-10-03)

- **Reminder devices are named,** like "Android phone · Chrome app" or "iPhone · Safari app".

## 0.6.6 (2026-10-03)

- **A reminder's Map button opens the place on Google Maps again.** The Android intent link from 0.6.4 is gone.

## 0.6.5 (2026-10-03)

- **A reinstalled phone signs back up for reminders,** or offers to. A device you switched off with Disable is left alone.
- **Fixes:** Settings tells this device's reminder state from this device, the toast's countdown is easier to see, Undo on a toast works on the first tap with the event sheet open, and Settings labels sit level with what they name.

## 0.6.4 (2026-10-03)

- **Folded sidebar groups stay folded across reloads,** kept per device.
- **A reminder's Map button asks for the Google Maps app on Android,** falling back to the web.
- **The web app manifest names the app's identity and has a full-bleed PNG icon,** so a full install has its own notification icon.

## 0.6.3 (2026-10-03)

- **Reminders have buttons:** Map for an event with a place, and Join for a video call (Zoom, Meet, Teams, Webex).
- **Fixes:** Better-Cal's notification icon is a calendar, not a square.

## 0.6.2 (2026-10-03)

- **A place quick fill finds opens the Location choices,** biased to where you'll be.
- **A new event starts in the quick-fill box;** editing an event starts in the title.
- **Toasts last ten seconds when there's an Undo** (six otherwise), with a countdown.
- **Fixes:** For me on a repeating event asks right where you tapped, quick fill keeps the place in the title, and day list rows line up.

## 0.6.1 (2026-09-28)

- **The phone event sheet puts the event first:** Edit and More are small icons beside the title, and everything rarer is in More.
- **The editor takes focus when it opens.** On a phone, editing an event doesn't raise the keyboard by itself.
- **Unticking All day makes an hour, not a day.**

## 0.6.0 (2026-09-27)

Creating events on phones.

- **The event editor is a full screen on a phone,** with Close, the title and Create in a top bar the keyboard never hides.
- **Back closes it.** Rows and text are thumb-sized and readable.
- **Pickers on touch:** times open the quarter-hour list, dates the phone's own calendar.
- **New on a day:** the day sheet has a New button.
- **Fixes:** dates this year leave the year off so they fit, and the form shrinks above the Android keyboard.

## 0.5.10 (2026-09-27)

- **Directions (and every map link) open the place itself in Google Maps,** not a pin on bare coordinates.

## 0.5.9 (2026-09-26)

- **Location search looks near where your calendar puts you on that date,** then your device's position, then your Home location.
- **Fixes:** the location dropdown no longer waits for your device's position, and "and" and "&" find the same place.

## 0.5.8 (2026-09-26)

- **Copy to another calendar moved into More.**
- **More says what each group acts on:** the event, the calendar it's on, and the site it came from.

## 0.5.7 (2026-09-26)

- **Forwarded emails are read on the clock of the place they're about:** a booking in Tokyo is 3 PM Tokyo time, and the event keeps that zone.
- **The app tells the server your device's time zone** whenever it changes.
- **The event panel's actions sit in a fixed toolbar at the top,** the same height for every event.
- **The event editor has labels on the left and one line per field.**
- **The time zone is a visible control in the editor:** a globe chip naming the zone.

Maintainers: deploys keep the newest 5 backups, not 20.

## 0.5.6 (2026-09-26)

- **Dates and times in the event editor are plain text boxes:** click in and type over the value ("7p", "noon", "fri", "oct 5").
- **Times have a quarter-hour list.** The end list shows each length, so an overnight end is one pick.
- **The end follows the start,** keeping the event's length unless you turn off the lock.
- **Maybe when you create an event:** "For me: Planned / Maybe" on your own calendars. Maybe saves it as tentative, which other calendar apps and Google see too.
- **A tighter editor,** and a blank new event starts at the next quarter hour.

Maintainers: every production deploy backs up the app directory and the database first, keeping the newest 20 of each. A failed backup stops the deploy.

## 0.5.5 (2026-09-26)

- **On desktop, an event opens in a panel on the right,** the same place and size every time, with everything in it.
- **The calendar makes room for it,** so every day stays visible.
- **The sidebar tucks away while the panel is open.** Settings → General → Event panel turns that off.

## 0.5.4 (2026-09-26)

- **Addresses with extra words now find their place on the map,** including ones already marked "couldn't place".

## 0.5.3 (2026-09-26)

- **Pulled up, the phone's event sheet is the full view:** map, description, plugin information and source included.
- **Invitations can be answered from the sheet:** Accept, Maybe or Decline.
- **Fixes:** on an event with a lot in it, the sheet scrolls instead of squeezing its rows.

## 0.5.2 (2026-09-26)

- **The phone's event sheet, redone inside:** bigger type and rows with an icon each.
- **One bar at the bottom at a time:** while an event is open, the sheet's actions take the place of the app's bottom bar.
- **Four labeled actions for every event:** Edit, Move, Copy to, More for yours; More like, Less like, Copy to, More for feed and suggested events. Delete is in More.
- **"For me" as buttons:** Planned or Maybe for your own events; Planned, Maybe or Hide for suggestions.
- **Meeting links are a Join button** (Zoom, Google Meet, Teams, Webex) and a real address gets Directions.

## 0.5.1 (2026-09-25)

- **The phone's event sheet points at the day you're on** when you step onto a multi-day event.
- **With the sheet open, tapping another event opens it** straight away.

## 0.5.0 (2026-09-25)

- **On phones, an event opens in a sheet that holds still,** at one height for every event.
- **Swipe the sheet sideways** or tap the arrows to step through the day's events.
- **Pull it up for everything:** drag the handle up to grow it, down to shrink or close it.
- **Stepping through a day follows what the view shows,** skipping what's hidden.

## 0.4.18 (2026-09-25)

- **The calendar picker's list looks scrollable at rest.**

## 0.4.17 (2026-09-25)

- **The calendar picker shows each calendar's color** in the editor and quick add (Chrome and Edge 135 and later; other browsers keep the plain list).
- **Fixes:** Escape while a dropdown list is open just closes the list, not the editor.

## 0.4.16 (2026-09-25)

- **A dropped ssh connection can no longer take the site down mid-deploy:** one connection carries every step, and copied files have the right owner the moment they land.
- **A failed deploy says so,** and a failing health check now fails it.
- **Deploys no longer re-own the whole app directory,** and never the `.env`.

Self-hosters: the deploy login still needs passwordless sudo (or is root); rsync is now received through `sudo -n -u APP_USER`.

## 0.4.15 (2026-09-25)

- **Holidays are written with the date, not as context,** so their names aren't cut short. In the phone's full month, tap the small flag for the name.

## 0.4.14 (2026-09-25)

- **The date title's hint is a small "jump to a date" icon** instead of a dropdown caret.

## 0.4.13 (2026-09-25)

- **The top bar's date is never cut short:** the weather after it clips instead, and in a tight header named days get cut before numbers.
- **Fixes:** phone day view dividers read the same as the top bar.

## 0.4.12 (2026-09-25)

- **Phone day view: the day's weather sits beside the date** in the top bar, on one line.

## 0.4.11 (2026-09-25)

- **Phone day view: one date, not two.** Each day's date is a thin divider that scrolls up under the top bar.
- **The today button reads as a date** ("25th"), and the date title has a caret showing it opens the date picker.

## 0.4.10 (2026-09-25)

- **Week view: all-day bars keep their order as you scroll.**
- **Fixes:** finished multi-day bars no longer trail along the left edge, and the "which occurrences?" choice fits a phone.

## 0.4.9 (2026-09-25)

- **Create and move events by touch in week and day views:** tap an empty time for a one-hour event, or press and hold, then drag.
- **Fixes:** "+N" in week view opens that day's full list, and phone week puts the weather back above the date.

## 0.4.8 (2026-09-25)

- **Phone week: pinch sideways to set how wide days are.** The phone remembers it.
- **Phone week: blocks show their title only,** and the all-day area is three slim lanes plus "+N".
- **Phone day view: all-day items get full-width rows** with their full names.
- **Week view: a long all-day bar keeps its title in view.**

## 0.4.7 (2026-09-25)

- **"Location available once RSVP'd" shows as a lock with "After RSVP",** not as a map.
- **Split: steadier scrubbing, no flick.**
- **Fixes:** Split is last on phones too, and the sidebar's buttons no longer shift when its scroll bar appears.

## 0.4.6 (2026-09-25)

- **Split: scrolling over the weeks scrubs through the days,** with the list following.
- **Split: with three or more weeks showing, the current week sits in the middle row.**
- **Phone lists: rows with actions carry a grip** (⋮); tap it for the actions.
- **Fixes:** folded "nothing on" days read more clearly, and the phone sidebar no longer scrolls sideways and keeps its scroll bar visible.

## 0.4.5 (2026-09-25)

- **Phone swipe actions:** Hide is soft rose, and the two thumbs sit a little apart from Maybe, Planned and Hide.

## 0.4.4 (2026-09-25)

- **Phone swipe actions sit in a slightly taller tray** with soft tints of their colors.
- **Fixes:** no sliver of the closed tray at the end of rows, and the last event's tray isn't cut off.

## 0.4.3 (2026-09-25)

A compact list on phones, for Split and the Agenda view.

- **One line per event:** the start time, the title, then the place. Full details stay on the event card.
- **Triage on a swipe:** drag a suggested event left for Maybe, Planned, Hide, More like this and Less like this.
- **Fixes:** slimmer day headings, and a stronger hatch on folded empty days.

## 0.4.2 (2026-09-25)

- **The phone's month uses each day's full height,** with "+N" only when a day truly overflows.
- **Fixes:** week numbers no longer collide with the month name in the left column.

## 0.4.1 (2026-09-25)

- **Split never claims "nothing on" for days it hasn't loaded:** they read "not loaded yet".
- **Fixes:** jumping in Split lands as soon as that day's events arrive, the server refuses more than two years of events with a clear error, and Split's day outline no longer drops out after a long jump.

## 0.4.0 (2026-09-25)

A new view: Split.

- **Split** puts a strip of weeks over a list that runs on without a break. Scroll either and the other follows.
- **Drag the handle between them** to show more weeks or more list.
- **Empty days fold** into one "nothing on" line.
- **Find Split** in the view menu, the phone's View sheet, the command palette and on key 7.
- **Fixes:** the phone's 3-day view drops start times so titles get the room, and long spans of events are fetched in pieces so the later part no longer stays empty.

## 0.3.10 (2026-09-24)

- **Fixes:** the month and 3-day views' left column alternates by month along with the days.

## 0.3.9 (2026-09-24)

- **Coming back after an update opens where you were.** A fresh launch, or a return after half an hour, still opens on today.
- **Swipe the sidebar in from the left edge** on phones and narrow windows.
- **Phone top bar:** previous and next sit before the month name, and Today is back at the right as a badge.
- **Phone bottom bar: Review** (invitations, held changes and proposals) takes Today's slot.
- **Fixes:** the New button stands out more, month names are larger and bolder, and the "some shown" mark can be tapped on touch screens.

## 0.3.8 (2026-09-24)

Phones: the top bar and the bottom bar's edge.

- **Previous and next are back on phones,** either side of the month name.
- **Previous, next and Today glide the month grid** when where they land is near.
- **Chips under the phone's top bar say what narrows the calendar;** tap one to open its control, or its × to clear it.
- **Fixes:** a firmer bottom bar edge, and no keyboard shortcuts in touch menus.

## 0.3.7 (2026-09-24)

- **Less context on phones:** none beside the full month's day numbers, and one item in the 3-day view.

## 0.3.6 (2026-09-24)

- **The installed app picks up new versions when you come back to it.**

## 0.3.5 (2026-09-24)

- **Fixes:** press and hold on the phone's New and Today buttons no longer ends with an extra tap, and the next tap after dragging an event works.

## 0.3.4 (2026-09-24)

- **Fixes:** Next and Previous in the Month view work in tall windows.

## 0.3.3 (2026-09-24)

- **A click or tap outside an open menu, popover, card or sheet only closes it.**

## 0.3.2 (2026-09-24)

- **Fixes:** checked Show choices in the phone Filter sheet no longer turn blue.

## 0.3.1 (2026-09-24)

Phones, phase 2 of the mobile pass: the phone frame.

- **A thin top bar on phones:** the menu, the month (tap it to jump to a date) and a saved view's name.
- **A bottom bar with View, Search, New, Filter and Today.** Press and hold New or Today for more.
- **Sheets open from the bottom** with a handle you can pull down to close.
- **Fixes:** the keyboard-shortcuts button hides on touch screens, and there's one set of breakpoints (phone below 640 pixels, compact below 800).

## 0.3.0 (2026-09-24)

Phones and touch, phase 1 of the mobile pass: fixes only.

- **Fixes:** tap areas of at least 44 by 44 points on touch screens, no zoom on iPhone Safari when you tap into a text field, phone layouts that fit the screen, and quick add's time fields fit their text.

## 0.2.6 (2026-09-24)

Two visible fixes.

- **All-day events edit as dates (#34).**
- **A calendar that cannot load its events says so (#48),** retries, and has a Retry now button.
- **Fixes:** search results show an all-day event on its own date.

## 0.2.5 (2026-09-24)

Nothing generated is committed any more.

Maintainers: the server fills in the modulepreload block and service worker list; `scripts/gen-preload.mjs` and the pre-commit hook are gone.

Operators: `/sw.js` must reach `index.php`. Remove any web server rule that serves it from disk (`docs/install.md` no longer has one), or the app loses offline start. The `/assets/sw.js` fallback is gone.

## 0.2.4 (2026-09-24)

Sign out everywhere else, for a lost or stolen device.

- **Settings → Account → Sign out everywhere else** signs out every other browser and stops their push reminders. It's written to Activity; API keys are left alone.
- **SECURITY.md's lost-device steps** now start here.

## 0.2.3 (2026-09-24)

Your own browsers get past the sign-in brake (issue #59, layer 1).

- **Remembered browsers:** a browser you've signed in with can still sign in while the overall brake is on. The password is still required.
- **A password reset forgets every remembered browser.**
- **Fixes:** when the brake refuses a sign-in, the message says new devices are paused.

Operators: migration 030 adds the `trusted_devices` table.

## 0.2.2 (2026-09-24)

Bundled plugins for context calendars.

- **Weather (0.2.0):** condition icons, and air quality as a daily event (a new setting, on by default).
- **Tides (0.2.0):** high and low tides carry their own icons.
- **New Sun plugin:** sunrise and sunset for a place, with no network access or key.

## 0.2.1 (2026-09-23)

API tokens get their capabilities back, safely.

- **Anything an API token creates belongs to it:** its outbound feeds and push devices go when it's revoked, and a reminder address it sets lasts only while it's valid.
- **Google:** linking an account still needs you signed in with your password.

Operators: the deploy scripts only change `.env` ownership when running as root, and the install guide says how to protect `.env` on shared hosting.

## 0.2.0 (2026-09-23)

The security pass is complete: every fix from 0.1.1 to 0.1.8, with `SECURITY.md` for what was reviewed. What works differently:

- **API tokens can't link Google accounts;** sign in with your password for that.
- **A password reset removes all push devices;** each browser registers again when you next open the app.
- **`seed.php`** asks for the password, and `--revoke-tokens` also gives outbound feeds new addresses and resets a custom reminder email address.
- **Limits:** oversized feeds, imports and CalDAV objects are refused, and huge repeat rules are treated as single events.
- **The editor auto-links `https://` and `www.` addresses,** not bare `example.com/page`.
- **CalDAV clients using API tokens** aren't subject to the password rate limit.

## 0.1.8 (2026-09-23)

Follow-up fixes from a third adversarial review.

- **Calendar-file budgets** can't be dodged with extra carriage returns.
- **The overall sign-in brake** needs failures from 10 different addresses.
- **Fixes:** exported descriptions keep text after a stray unclosed tag, older CalDAV object names still resolve, and Settings shows "Enabled" only when this device is registered.

## 0.1.7 (2026-09-23)

Closing the gaps a second adversarial review found in the security releases.

- **The overall sign-in brake** needs failures from at least 20 different addresses.
- **Calendar-file budgets** count events the way the parser reads them and bound the number of lines.
- **The last slow patterns on untrusted text are gone.**
- **The MCP server marks third-party text** in every tool result.
- **Google:** connecting, disconnecting and adding Google calendars needs you signed in with your password.

## 0.1.6 (2026-09-23)

Fixes for regressions an adversarial review found in the security releases above.

- **CalDAV:** devices syncing with API tokens keep working when the password limit is hit, and simultaneous correct-password connections aren't refused.
- **Reminders:** a device removed in Settings stays removed.
- **Fixes:** mail with some non-English text, IPv6-only servers reaching IPv4 hosts through NAT64, and prompt filters with non-Gemini models.

Operators: the dev clone keeps Google calendars as local copies, `DB_USER` is only needed when cloning, and database and user names may contain hyphens.

## 0.1.5 (2026-09-23)

Security fixes from the 2026-09-23 scan (remaining low-severity findings).

- **The Google sign-in landing page shows fixed messages,** never text from the link.
- **Accepting an organizer's emailed change** keeps the invitation bound to its original organizer.
- **Outbound fetches can't reach private IPv4 through IPv6 wrappers.**
- **The push service no longer sees which event or when.**
- **Prompt filters, ranking and the MCP server** mark third-party text as data, not instructions.
- **Crafted descriptions** no longer freeze the event view or the editor, and bare addresses like "example.com/page" aren't auto-linked.

## 0.1.4 (2026-09-23)

Security fixes from the 2026-09-23 scan (sign-in hardening).

- **Sign-in limits** hold against simultaneous guesses, and response times no longer reveal which email addresses have accounts.
- **The overall sign-in brake** needs failures from at least six different addresses.
- **`seed.php` asks for the password with echo off** or reads `BETTERCAL_SEED_PASSWORD`; `--password=` still works but warns.

## 0.1.3 (2026-09-23)

Security fixes from the 2026-09-23 scan.

- **Unusual event UIDs no longer break CalDAV sync.** A CalDAV client may re-download such a calendar once.
- **Oversized subscribed feeds are refused** with a clear poll error (`BETTERCAL_LIMIT_FEED_EVENTS`, 20,000 by default).
- **Crafted email** can no longer hold up mail processing.

## 0.1.2 (2026-09-23)

Security fixes from the 2026-09-23 scan. Updating is recommended for every install.

- **Revoking access revokes everything:** a password reset removes every push device, and `seed.php --revoke-tokens` also resets outbound feed addresses and the reminder email address.
- **API tokens can't manage outbound feeds, push devices or where reminders go.**
- **Settings → Notifications lists every device reminders go to,** with Remove. Changes are written to Activity.

Operators: the deploy leaves `.env` readable but not writable by the web app. The dev-instance clone takes its database user from `scripts/deploy.env` (new `DB_USER` setting) and strips sessions, tokens, push devices, feeds and Google links.

## 0.1.1 (2026-09-23)

Security fixes from the 2026-09-23 scan.

- **A successful sign-in no longer resets the failed-password count.**
- **Out-of-range repeat rules are dropped,** and one event that can't be expanded no longer fails its calendar.

## 0.1.0 (2026-09-23)

Baseline: the first tagged version, covering everything built from July to September 2026 (see `FEATURES.md`). Earlier commits are unversioned.
