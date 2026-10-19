# Email ingest: mail becomes calendar events

The worker checks the ingest mailbox about every two minutes. It reads calendar attachments, booking details and invitation links, then adds events to the local Invitations calendar or holds them in Review, according to your choice in Settings, General.

## Choose what gets added

**Add events from email** has three choices:

- **From any sender:** add every new event the reader recognizes. An invitation is added unanswered, as Maybe. No reply is sent.
- **From approved senders:** the default for new and existing installs. Events from approved addresses or domains are added automatically; other events wait in Review.
- **Hold everything for Review:** every new emailed event waits for you to add or dismiss it.

The choice applies to invitations as well as bookings, regardless of how the event was read. It does not change how updates and cancellations to invitations already on your calendar are handled.

In Review, **Add** adds just this event. **Always add from bookings@example.com** adds it and approves that address. **Always add from example.com** adds it and approves every address at that domain, useful when a merchant sends from several addresses. Neither approval button sends a reply. The domain button is absent for common shared mailbox providers such as Gmail, Outlook, iCloud and Yahoo, where only individual addresses can be approved. The built-in list cannot name every small mail provider; approve a domain only when you mean everyone who can send from it. Settings shows the approved list and lets you remove entries. API clients can also save addresses, domains and `*.example.com` entries; the wildcard covers subdomains, not the base domain. A bulk text editor is not included yet.

On upgrade, successful senders recorded in the retained mail history are approved as exact addresses. Already added events are untouched. History is kept for 90 days, so a sender whose only successful mail is older than that may need approval the next time. Existing held items stay in Review when you change the mode or approve a sender.

These are convenience preferences, not sender authentication: a sender can forge the From address. Keep the ingest address private. A mailbox alias you never give out makes it harder to guess. If you give the address directly to a booking service, use **From approved senders** and approve that service after checking its first event.

## How messages are read

The reader tries these sources in order:

1. A `text/calendar` part or `.ics` attachment, including iMIP invitations and PUBLISH bookings.
2. Structured booking or event details in HTML, such as schema.org Event and Reservation data.
3. An **Add to Google Calendar** template link. These often survive forwarding even when structured HTML is removed.
4. Gemini extraction from the subject and body, only when the earlier sources found nothing and the subject looks like an invitation, confirmation, ticket or booking. A body with no date evidence is skipped without a paid call.

Mail is processed one message at a time. The server checks its size before decoding headers or downloading the body. Messages over 5 MiB and calendar parts over 1 MiB or 200 events are not parsed. Text and HTML supplied to extraction are limited to 512 KiB. The original email stays in the mailbox.

Each unreadable or oversized message produces an **An email couldn't be read** notice in Review, with its sender and subject when those could be recovered. Check the original email and your calendar, then add any missing event by hand or forward a corrected copy. The notice stays until you dismiss it; it does not depend on the 90-day mail log. **Show older notices and decisions** reaches entries beyond the first page. Ordinary mail with no recognized event is not a parsing failure and does not create a notice.

A stable mailbox identifier is recorded before parsing begins. A malformed message is marked handled instead of being retried forever, even if the mailbox refuses its read flag. Later messages still get a turn. If the host stops the worker while parsing, the next worker run turns its unfinished checkpoint into a notice. Connection failures are retried when the message can safely be made unread again; otherwise a notice asks you to check it. Message receipts prevent replay for 90 days, then are removed.

## Aggregate safety and paid-model limits

Per-message limits are not enough when many messages arrive. These persistent limits remain in effect in every mode:

- At most 100 new email events or Review candidates in a rolling 24 hours, 25 from one claimed From address, and 500 active email-created events at once. A repeating series counts while it can still produce an occurrence; ended COUNT and UNTIL series stop counting. A moved future occurrence still counts. A repeat rule that cannot be checked produces a visible capacity problem, not a false claim that it ended.
- At most 100 open emailed event decisions in Review. Unreadable-email notices are separate and remain visible even when this queue is full.
- At most 20 mail-reading Gemini calls in an hour, 50 in 24 hours, and 20 in 24 hours for one claimed From address.

From, Message-ID and calendar UID are sender-controlled, so changing them does not reset the account-wide limits. A full model budget does not block deterministic extraction. New-event limits do not stop you deciding changes to an event already on your calendar.

When a limit is reached, no over-budget event or model call is made. The email is marked read and stays in the mailbox. Review shows one **Email automation paused** notice per reason, with the affected count and the latest subject and sender. Dismissing it changes no events. For a legitimate burst, add missing events manually from the mailbox or adjust the documented `BETTERCAL_LIMIT_MAIL_*` settings within their hard ceilings. Retrying later requires forwarding a new copy; handled mail is not automatically replayed.

Feed-triggered ranking has its own model budget: 40 batches per account per hour and 200 per day, with 20 per calendar per hour and 100 per day. A saturated calendar does not prevent another calendar using its allowance. Review shows a notice, existing scores stay unchanged, and ordinary worker runs try again when capacity is available. These budgets are separate from mail extraction and prompt filters.

## Invitations, changes and cancellations wait for you

A new invitation follows **Add events from email**. When added automatically or with **Add**, it is a Maybe with no RSVP. Review's explicit **Add and accept** also sends an acceptance when a reply route is configured. An added invitation's organizer becomes the address used to check later changes, whether it was added through your mail preference or an explicit decision. Older invitations without that recorded choice remain unbound until an owner decision.

Changes or cancellations to an invitation already on your calendar are never applied just because its sender is approved. They pass the existing organizer checks, then wait in Review with the old and new values. Messages are refused if they collide with an event without an invitation, target a Google-managed invitation, fail the organizer check, or carry a sequence older than the one already applied. Reopening a cancelled invitation requires a higher sequence. A message that changes nothing visible creates no decision.

Pending sequence numbers are only claims. Different pending revisions remain side by side, including a lower claimed sequence, so a forged high number cannot hide a genuine change. Exact replays share one item. Accepting rechecks the current event under the account lock; only an applied decision closes revisions that have become stale or redundant. Other revisions remain available. A preview that no longer matches the current event must be reviewed again. Dismiss leaves the calendar alone, and deleting an event closes its pending changes.

The organizer address check is not proof of who sent the message. DKIM verification could help later, but forwarding can break it, so it cannot replace owner review of changes.

## RSVP

Only an iMIP REQUEST is an invitation with someone to answer. PUBLISH, structured bookings, calendar links and extracted reservations are bookings with no reply buttons. Once an invitation is on the calendar, **Accept**, **Maybe** and **Decline** send an iMIP reply if the server has a configured sending address on its guest list. The organizer's own address is never used as the guest. If a route is missing, the event explains why.

Accept makes the event Planned; Maybe and Decline leave it as Maybe. Changing Planned or Maybe by hand sends nothing. Google calendar invitations are answered at Google instead; see [Google invitations](google-calendar.md#invitations).

Email replies reserve the same persistent outbound-email allowance as reminders and system alerts before sending. A limit refusal saves your answer here and clearly says no reply was sent. Only one reply for an event can be in progress, and repeating an answer already sent sends nothing new. SMTP connections and commands have explicit timeouts; an outside send and a database save cannot be one transaction.

If a request stops after sending but before saving the result, Better-Cal cannot know whether the organizer received it. The event explains that uncertainty. **Retry** asks you to confirm that another copy may be sent; it never automatically resends an uncertain reply. A failed send still uses its reserved allowance. Your initiating browser session is checked again immediately before sending and before saving the result, but an email already submitted cannot be recalled.

Configure the dedicated RSVP sending account if invitations arrive for a different address from the main notification account. For Gmail, use an app password:

```text
BETTERCAL_RSVP_SMTP_HOST=smtp.gmail.com
BETTERCAL_RSVP_SMTP_PORT=587
BETTERCAL_RSVP_SMTP_USER=you@gmail.com
BETTERCAL_RSVP_SMTP_PASS=<app password>
BETTERCAL_RSVP_SMTP_FROM=you@gmail.com
```

Create it in Google Account, Security, 2-Step Verification, App passwords. Without this profile, replies use the main SMTP account. Forwarding does not always preserve a calendar attachment; check whether the result is an invitation or a booking before expecting reply controls.

## Forwarding setup

In your mailbox provider's settings, add the ingest address as a forwarding destination and confirm it using the message delivered there. The worker may already have marked that confirmation read. Create forwarding rules for the booking services you use, while keeping the original messages in your usual mailbox. Use approved senders rather than forwarding all mail without review.

If Google Calendar is also creating events from those messages, you can turn off its **Events from Gmail** setting to avoid duplicate events.

## Server settings

IMAP defaults reuse the main SMTP mailbox host, user and password on port 993. Set `BETTERCAL_IMAP_HOST`, `BETTERCAL_IMAP_PORT`, `BETTERCAL_IMAP_USER` and `BETTERCAL_IMAP_PASS` if the ingest mailbox differs. The existing webklex/php-imap library supplies the protocol client; the PHP IMAP extension is not needed. Plugin location queries honor `BETTERCAL_PLACE_LOOKUP=off` just like application queries.
