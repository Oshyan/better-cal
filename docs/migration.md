# Migrating from Google Calendar

Two kinds of calendars, two paths. Both support a gradual transition — nothing here is a cliff.

## Subscribed calendars (holidays, team feeds, ...)

Don't migrate — re-point. Each subscription in GCal shows its original source URL (GCal Settings → the calendar → "Integrate calendar" or the original subscribe link). Subscribe Better-Cal to the same URLs (sidebar → + Subscribe URL, or `cal.oshyan.com/subscribe?url=...`). Fresh data forever, nothing lost.

## Native calendars (your data)

### Option A: Takeout cutover (recommended for the final move)

1. [takeout.google.com](https://takeout.google.com) → deselect all → select **Calendar** → export. One zip, every native calendar as an .ics.
2. Unzip and put the `Calendar/` folder on the server (or hand it to the agent).
3. `php server/bin/import-takeout.php /path/to/Takeout/Calendar` — creates one local calendar per file (named from the filename, palette-colored) with full recurrence structure. Rerun-safe: already-imported calendar names are skipped.

### Option B: transition mode (live mirror, then adopt)

For daily-driving Better-Cal *before* committing:

1. In GCal, each calendar's settings shows a **"Secret address in iCal format"** URL. Subscribe Better-Cal to it (+ Subscribe URL). The calendar now live-mirrors GCal, full history included, updating on the poll interval.
2. Live on Better-Cal for a while. New events you create go in Better-Cal calendars; the mirrors keep showing whatever still lands in GCal (invites, shared edits).
3. When ready to cut over a calendar: its settings panel (sidebar gear) → **"Adopt as local calendar"**. The feed link is severed and every event becomes a local, editable copy in place — same calendar, same history, now Better-Cal-native. (`POST /api/v1/calendars/:id/adopt`.)

### After the move

- Invites: set up the Gmail forward filter (docs/email-ingest.md) so invitations sent to your Gmail keep landing in Better-Cal.
- GCal links on the web: the Chrome extension (extension/README.md) redirects "Add to Google Calendar" buttons to Better-Cal; on mobile, share the link to the installed PWA or paste it into quick add.
- Leave the GCal calendars in place but stop looking at them; delete whenever confidence is total.
