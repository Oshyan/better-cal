# Email ingest: invites become events

The worker polls the **calendar@oshyan.com** mailbox (IMAP, every ~2 minutes) and turns messages into events on a local **Invitations** calendar, mirroring Gmail's own server-side behavior with a three-tier ladder:

1. **iMIP** — a `text/calendar` part or `.ics` attachment. Authoritative: `METHOD:REQUEST` creates or updates by UID (so reschedules amend in place), `METHOD:CANCEL` marks the event cancelled. Organizer, attendees, and sequence land in `events.invite_json`.
2. **schema.org markup** — JSON-LD `Event` / `*Reservation` blocks embedded in HTML by Eventbrite, Luma, airlines, OpenTable, etc. Deterministic, no ML.
3. **LLM extraction** — Gemini over subject+text, only when tiers 1–2 found nothing **and** the subject looks eventish (invite/confirm/ticket/registration/rsvp/booking/reservation/event). Keeps ordinary mail off the calendar.

Every processed message id is logged in `mail_ingest` (tier, outcome, event id) — nothing ingests twice; malformed messages are marked seen and skipped.

## RSVP

Ingested invitations show an RSVP row (Accept / Maybe / Decline) in the event detail view. `POST /api/v1/events/:id/rsvp {answer}` records `myPartstat` and emails an iMIP `REPLY` to the organizer.

**Sender identity matters**: organizers match replies to the invited address. Configure the dedicated RSVP SMTP profile so replies come from the Gmail address that was actually invited:

```
BETTERCAL_RSVP_SMTP_HOST=smtp.gmail.com
BETTERCAL_RSVP_SMTP_PORT=587
BETTERCAL_RSVP_SMTP_USER=oshyan@gmail.com
BETTERCAL_RSVP_SMTP_PASS=<16-char Google app password>
BETTERCAL_RSVP_SMTP_FROM=oshyan@gmail.com
```

App password: Google Account → Security → 2-Step Verification (must be on) → App passwords. Without this profile, replies fall back to the calendar@ SMTP profile — many servers accept that (the reply names the right attendee), Google organizers may not.

## One-time Gmail setup (user actions)

1. **Forwarding address**: Gmail → Settings → Forwarding → Add forwarding address → `calendar@oshyan.com`. The confirmation code arrives in the calendar@ mailbox (Better-Cal's worker inbox — ask the agent to fish it out, or check via webmail).
2. **Filter**: Gmail → Settings → Filters → Create:
   - Matches: `has:attachment filename:ics OR from:(eventbrite.com OR lu.ma OR luma.com OR meetup.com OR splashthat.com OR opentable.com)`
   - Action: *Forward to* calendar@oshyan.com. (Keep "skip inbox" OFF so Gmail retains your copy.)
   - Garden the sender list when a new platform shows up.
3. **Stop Google shadow-adding**: Google Calendar → Settings → Events from Gmail → turn off "Show events automatically created by Gmail" (and in Gmail: Settings → General → Smart features, if you want it fully off).

Anything registered directly with calendar@oshyan.com skips all of this and just works (the domain wildcard already routes to the mailbox).

## Config

IMAP defaults reuse the SMTP mailbox credentials (`BETTERCAL_SMTP_HOST/USER/PASS`, port 993); override with `BETTERCAL_IMAP_HOST/PORT/USER/PASS` if the mailbox ever moves. Transport is webklex/php-imap (own protocol client, no ext-imap needed).
