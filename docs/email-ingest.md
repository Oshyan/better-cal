# Email ingest: invites become events

The worker polls the **calendar@example.com** mailbox (IMAP, every ~2 minutes) and turns messages into events on a local **Invitations** calendar, mirroring Gmail's own server-side behavior with a four-step ladder:

1. **iMIP** — a `text/calendar` part or `.ics` attachment. A first-time `METHOD:REQUEST` is an unauthenticated invitation and waits in **Review** without creating an event. **Add to calendar** creates it but does not send an RSVP; it then appears as the ordinary Accept / Maybe / Decline decision. An iMIP `PUBLISH` is a booking and still becomes an event directly. A REQUEST or `METHOD:CANCEL` for an invitation you already have is also held until you accept or dismiss it.
2. **schema.org markup** — JSON-LD `Event` / `*Reservation` blocks embedded in HTML by Eventbrite, Luma, airlines, OpenTable, etc. Deterministic, no ML.
3. **Add to Google Calendar links**: the `calendar.google.com` template link in an "Add to calendar" button. Also deterministic, and it survives a Gmail forward, which strips the markup but keeps links.
4. **LLM extraction** — Gemini over subject+body, only when tiers 1–3 found nothing **and** the subject looks eventish (invite/confirm/ticket/registration/rsvp/booking/reservation/event). A body with no date in it at all is skipped without a call. Keeps ordinary mail off the calendar.

Every processed message id is logged in `mail_ingest` (tier, outcome, event id) for a 90-day replay/diagnostic window — mail is not ingested twice during that window; malformed messages are marked seen and logged (`skipped` or `error`), not retried.

Anyone can send mail to the ingest address, so a message is sized before it is trusted. The worker searches only for stable IMAP UIDs and asks the server for each `RFC822.SIZE` before it creates a message object or decodes Subject, From, or Message-ID. One over 5 MiB is marked read without downloading or decoding those headers (outcome `skipped`, error "message too large to ingest"). Messages are then materialized one at a time rather than ten decoded at once, a calendar part over 1 MiB or holding more than 200 events is ignored, and text/HTML is cut to 512 KiB before extraction. A transport-keyed row is logged as `started` and the message is marked seen BEFORE its header and body are decoded: if parsing kills the worker outright (out of memory cannot be caught), the next run does not walk into the same message again, and the `started` row that is left behind is how such a message shows up afterwards. An ordinary failure (a dropped connection) undoes both, so it is retried. The limits are `BETTERCAL_LIMIT_MAIL_*` in `.env`.

## Aggregate safety and paid-model limits

Per-message size limits do not stop a public sender from sending many small, unique messages. Better-Cal therefore applies persistent account-wide admission before either durable event creation or a paid Gemini request:

- At most 100 new mail-created events or first-invitation Review candidates in any rolling 24 hours, 25 from one claimed From address, and 500 future/repeating mail-created events at once.
- At most 100 open emailed invitation decisions in Review. This is a persistent hard ceiling, not a rolling window, so unattended public mail cannot grow or bury the decision queue indefinitely.
- At most 20 mail-reading Gemini calls in an hour, 50 in 24 hours, and 20 in 24 hours for one claimed From address.
- The account-wide limits are the security boundary. From, Message-ID, and iCalendar UID are all sender-controlled, so changing them cannot reset the global budget; the sender limits are only a secondary noise brake.
- Event and model budgets are separate. A full model budget does not block iMIP, schema.org, or Google-link extraction, and a full new-event budget does not block Review handling for a change or cancellation to an existing event.

When a limit is reached, the email is marked read but no event is added and no over-budget model call is made. The original remains in the mailbox. Review gets one aggregate **Email automation paused** item per reason, updated with the number affected and the latest bounded Subject/From context instead of one attacker-controlled item per email. Dismissing the notice never changes the calendar. Legitimate bursts above a limit therefore require adding the excess items manually from the retained email, waiting for the rolling window, or deliberately tuning the documented `BETTERCAL_LIMIT_MAIL_*` setting within its hard ceiling.

## Invitations, changes and cancellations wait for you

Email is not authenticated. For a new UID, the first message therefore cannot create an event or establish a trusted organizer. It waits in Review with its organizer, sender address, claimed sequence, time and place. Distinct candidates with the same sender-controlled UID remain visible, so a forged high sequence cannot hide the genuine invitation. Dismiss adds nothing. **Add to calendar** is the owner action that establishes the organizer anchor and creates the event atomically; it sends no mail and makes no RSVP choice.

The only thing tying a later message to an existing invitation is the organizer's address, and a sender writes that. So a message that would change or cancel an event already on your calendar goes through three gates:

1. **Refused outright** (logged in Activity as "Blocked an emailed change to ..."): after an owner decision has established the organizer, neither the organizer nor sender address matches it; the event never carried an invitation at all (a UID collision with one of your own events); the UID belongs to a Google-managed invitation; the `SEQUENCE` is lower than the one already applied; or the event is cancelled and the `SEQUENCE` is not higher (an older REQUEST replayed after a CANCEL). Legacy invitations created under the old first-arrival behavior are treated as unbound until an explicit Review or RSVP decision establishes trust.
2. **Nothing to decide** (outcome `unchanged`): it passes, but changes nothing you can see, such as a re-send or an attendee-list update.
3. **Held** (outcome `held`): everything else. It appears on the **Review** page with what it would change (old value, new value), you get a notification, and your calendar is untouched. Accept applies it as an ordinary edit, so it shows in Activity and can be undone, and records the new `SEQUENCE`, for cancellations too. Dismiss keeps your version and is logged. Once the owner has established organizer trust, pending changes are ordered by their claimed `SEQUENCE`: an older arrival cannot replace a newer revision already waiting. Before that anchor exists on a legacy invitation, distinct candidates stay side by side because neither organizer nor sequence is yet trustworthy; choosing one establishes the anchor and closes its competing claims. Holding and accepting share an account lock, so accepting a stale displayed item cannot race a newer arrival. An item whose event you delete closes itself.

In-process DKIM verification (issue #25) could later let changes from a verifiably aligned organizer domain skip the queue. It cannot replace the queue, because forwarded mail routinely breaks DKIM and would have to fall back to the address match.

## RSVP

**Invitations and bookings are different things.** Only an iMIP `REQUEST` is an invitation, and a first-time one must be added from Review before it appears on the calendar. A reservation, ticket or confirmation read from mail (the markup, Google-link and LLM tiers, and iMIP `PUBLISH`) is a booking: it lands on the calendar like any event, reads "Booking via <site>" in its details, and has no reply buttons, since there is nobody to answer.

**An invitation shows Accept / Maybe / Decline when a reply can go:** it names an organizer, and there is an account to send from (below) that is one of the invited addresses. When one of those is missing, the event says which instead of showing buttons: no organizer, no account to send from, or "replies would come from X, which isn't one of the invited addresses" (the organizer's calendar ignores a reply from an address it didn't invite). Invitations you can answer and haven't also appear on the Review page.

**Whether it went:** `POST /api/v1/events/:id/rsvp {answer}` records the answer, emails an iMIP `REPLY`, and says what happened. The answer is kept either way. If the email didn't go, the event says so with the reason ("Accepted here; the reply couldn't be sent: ...") and offers Retry, and the answer can still be changed. A changed answer sends a new reply, and each answer is an entry in Activity.

Invitations on a connected Google calendar are answered at Google instead; see [google-calendar.md](google-calendar.md#invitations).

**Unverified:** whether an invitation forwarded from Gmail keeps its calendar part (so it reads as a `REQUEST` and can be answered). Every forwarded message so far has been a booking. To check, forward one invitation to the ingest address and see whether its Activity entry is badged "Email · imip".

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

1. **Forwarding address**: Gmail → Settings → Forwarding → Add forwarding address → `calendar@example.com`. Gmail sends a confirmation code to that address, so it lands in the ingest mailbox itself: open the mailbox in its webmail or a mail app, and copy the code into Gmail. (The worker may already have marked it read.)
2. **Filter**: Gmail → Settings → Filters → Create:
   - Matches: `has:attachment filename:ics OR from:(eventbrite.com OR lu.ma OR luma.com OR meetup.com OR splashthat.com OR opentable.com)`
   - Action: *Forward to* calendar@example.com. (Keep "skip inbox" OFF so Gmail retains your copy.)
   - Garden the sender list when a new platform shows up.
3. **Stop Google shadow-adding**: Google Calendar → Settings → Events from Gmail → turn off "Show events automatically created by Gmail" (and in Gmail: Settings → General → Smart features, if you want it fully off).

You can also give the ingest address straight to a service (an event site's sign-up, a booking form) instead of your own address. Its mail then arrives without passing through Gmail, so no filter is needed for it.

## Config

IMAP defaults reuse the SMTP mailbox credentials (`BETTERCAL_SMTP_HOST/USER/PASS`, port 993); override with `BETTERCAL_IMAP_HOST/PORT/USER/PASS` if the mailbox ever moves. Transport is webklex/php-imap (own protocol client, no ext-imap needed).
