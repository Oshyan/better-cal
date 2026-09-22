# Email ingest: invites become events

The worker polls the **calendar@example.com** mailbox (IMAP, every ~2 minutes) and turns messages into events on a local **Invitations** calendar, mirroring Gmail's own server-side behavior with a three-tier ladder:

1. **iMIP** — a `text/calendar` part or `.ics` attachment. A `METHOD:REQUEST` with a UID you do not have yet becomes an event. Organizer, attendees, and sequence land in `events.invite_json`. A REQUEST or `METHOD:CANCEL` for an invitation you ALREADY have is never applied on arrival: it is **held in the Review queue** (see below) until you accept or dismiss it.
2. **schema.org markup** — JSON-LD `Event` / `*Reservation` blocks embedded in HTML by Eventbrite, Luma, airlines, OpenTable, etc. Deterministic, no ML.
3. **LLM extraction** — Gemini over subject+text, only when tiers 1–2 found nothing **and** the subject looks eventish (invite/confirm/ticket/registration/rsvp/booking/reservation/event). Keeps ordinary mail off the calendar.

Every processed message id is logged in `mail_ingest` (tier, outcome, event id) — nothing ingests twice; malformed messages are marked seen and skipped.

Anyone can send mail to the ingest address, so a message is sized before it is trusted. The worker lists unseen mail WITHOUT bodies and asks the server for each size; one over 5 MiB is never downloaded (outcome `skipped`, error "message too large to ingest"). Messages are handled one at a time rather than ten decoded at once, a calendar part over 1 MiB or holding more than 200 events is ignored, and text/HTML is cut to 512 KiB before extraction. A message is logged as `started` and marked seen BEFORE its body is downloaded and parsed: if parsing it kills the worker outright (out of memory cannot be caught), the next run does not walk into the same message again, and the `started` row that is left behind is how such a message shows up afterwards. An ordinary failure (a dropped connection) undoes both, so it is retried. The limits are `BETTERCAL_LIMIT_MAIL_*` in `.env`.

## Changes and cancellations wait for you

Email is not authenticated. The only thing tying a later message to the original invitation is the organizer's address, and a sender writes that. So a message that would change or cancel an event already on your calendar goes through three gates:

1. **Refused outright** (logged in Activity as "Blocked an emailed change to ..."): the organizer or sender address does not match the organizer recorded when the invitation first arrived; the event never carried an invitation at all (a UID collision with one of your own events); the `SEQUENCE` is lower than the one already applied; or the event is cancelled and the `SEQUENCE` is not higher (an older REQUEST replayed after a CANCEL).
2. **Nothing to decide** (outcome `unchanged`): it passes, but changes nothing you can see, such as a re-send or an attendee-list update.
3. **Held** (outcome `held`): everything else. It appears on the **Review** page with what it would change (old value, new value), you get one notification per meeting, and your calendar is untouched. Accept applies it as an ordinary edit, so it shows in Activity and can be undone, and records the new `SEQUENCE`, for cancellations too. Dismiss keeps your version and is logged. A newer change for the same meeting replaces an older one still waiting, and an item whose event you delete closes itself.

In-process DKIM verification (issue #25) could later let changes from a verifiably aligned organizer domain skip the queue. It cannot replace the queue, because forwarded mail routinely breaks DKIM and would have to fall back to the address match.

## RSVP

Invitations you have not answered also appear on the Review page, with Accept / Maybe / Decline inline.

Ingested invitations show an RSVP row (Accept / Maybe / Decline) in the event detail view. `POST /api/v1/events/:id/rsvp {answer}` records `myPartstat` and emails an iMIP `REPLY` to the organizer.

**Sender identity matters**: organizers match replies to the invited address. Configure the dedicated RSVP SMTP profile so replies come from the Gmail address that was actually invited:

```
BETTERCAL_RSVP_SMTP_HOST=smtp.gmail.com
BETTERCAL_RSVP_SMTP_PORT=587
BETTERCAL_RSVP_SMTP_USER=you@gmail.com
BETTERCAL_RSVP_SMTP_PASS=<16-char Google app password>
BETTERCAL_RSVP_SMTP_FROM=you@gmail.com
```

App password: Google Account → Security → 2-Step Verification (must be on) → App passwords. Without this profile, replies fall back to the calendar@ SMTP profile — many servers accept that (the reply names the right attendee), Google organizers may not.

## One-time Gmail setup (user actions)

1. **Forwarding address**: Gmail → Settings → Forwarding → Add forwarding address → `calendar@example.com`. The confirmation code arrives in the calendar@ mailbox (Better-Cal's worker inbox — ask the agent to fish it out, or check via webmail).
2. **Filter**: Gmail → Settings → Filters → Create:
   - Matches: `has:attachment filename:ics OR from:(eventbrite.com OR lu.ma OR luma.com OR meetup.com OR splashthat.com OR opentable.com)`
   - Action: *Forward to* calendar@example.com. (Keep "skip inbox" OFF so Gmail retains your copy.)
   - Garden the sender list when a new platform shows up.
3. **Stop Google shadow-adding**: Google Calendar → Settings → Events from Gmail → turn off "Show events automatically created by Gmail" (and in Gmail: Settings → General → Smart features, if you want it fully off).

Anything registered directly with calendar@example.com skips all of this and just works (the domain wildcard already routes to the mailbox).

## Config

IMAP defaults reuse the SMTP mailbox credentials (`BETTERCAL_SMTP_HOST/USER/PASS`, port 993); override with `BETTERCAL_IMAP_HOST/PORT/USER/PASS` if the mailbox ever moves. Transport is webklex/php-imap (own protocol client, no ext-imap needed).
