# Email Ingest: Mail Becomes Calendar Events

Forward booking confirmations, tickets and invitations to a mailbox Better-Cal reads, and they become events. The worker checks that mailbox about every two minutes, reads calendar attachments, booking details and invitation links, and adds events to the local Invitations calendar or holds them in Review, depending on your choice in Settings, General.

## Server Settings

The ingest mailbox defaults to the main SMTP account's host, user and password, on port 993. If it's a different mailbox, set `BETTERCAL_IMAP_HOST`, `BETTERCAL_IMAP_PORT`, `BETTERCAL_IMAP_USER` and `BETTERCAL_IMAP_PASS`. PHP's IMAP extension isn't needed.

## Forwarding Setup

In your mail provider's settings, add the ingest address as a forwarding destination and confirm it with the message sent there (the worker may already have marked that one read). Then create forwarding rules for the booking services you use, keeping the originals in your usual mailbox. Forward from specific senders rather than forwarding all your mail.

If Google Calendar also creates events from those messages, turn off its **Events from Gmail** setting to avoid duplicates.

## Choose What Gets Added

**Add events from email** has three choices:

- **From any sender:** add every new event the reader recognizes. An invitation is added unanswered, as Maybe. No reply is sent.
- **From approved senders** (the default): events from approved addresses or domains are added automatically; others wait in Review.
- **Hold everything for Review:** every new emailed event waits for you to add or dismiss it.

The choice covers invitations and bookings alike, however the event was read. Updates and cancellations to invitations already on your calendar always wait in Review ([below](#invitations-changes-and-cancellations-wait-for-you)).

An event from a sender you haven't approved waits in Review with three ways to add it, none of which sends a reply:

- **Add** adds just this one.
- **Always add from bookings@example.com** adds it and approves that address.
- **Always add from example.com** adds it and approves every address at that domain, handy when a merchant sends from several. This button doesn't appear for shared mail providers like Gmail, Outlook, iCloud and Yahoo, since approving the domain would let in everyone who uses it. The built-in list of those can't name every small provider, so approve a domain only when you mean everyone who can send from it.

Settings lists your approved senders, and you can remove any of them there. The API can also add addresses, domains and wildcards like `*.example.com` (which covers `mail.example.com` and the like, but not `example.com` itself). Editing the whole list at once is planned ([#151](https://github.com/Oshyan/better-cal/issues/151)). Changing the mode or approving a sender doesn't release events already waiting in Review; add or dismiss those yourself.

Updating from a version before 0.9.87 approves every sender whose events were added in the last 90 days, as exact addresses. That can include your own forwarding address, so check the list after updating.

**Approval isn't proof of who sent a message:** anyone can put any address in the From line. So keep the ingest address private (an alias you never give out is harder to guess). If you give the address straight to a booking service, keep **From approved senders** on and approve the service after checking its first event. [SECURITY.md](../SECURITY.md#email-and-outside-services) covers the rest of the risk.

## How Messages Are Read

The reader tries these in order:

1. A `text/calendar` part or `.ics` attachment, including iMIP invitations and PUBLISH bookings.
2. Structured booking or event details in the HTML, such as schema.org Event and Reservation data.
3. An **Add to Google Calendar** link. These often survive forwarding even when the structured HTML doesn't.
4. Gemini extraction from the subject and body, only when nothing above worked and the subject looks like an invitation, confirmation, ticket or booking. A body with no date in it is skipped without a paid call.

The original email stays in the mailbox. Each message is remembered for 90 days so it's never added twice.

Size is checked before anything is downloaded or decoded. A message over 5 MiB isn't read, nor is a calendar part over 1 MiB or 200 events, and extraction sees at most 512 KiB of text. The settings are `BETTERCAL_LIMIT_MAIL_BYTES`, `BETTERCAL_LIMIT_MAIL_ICS_BYTES`, `BETTERCAL_LIMIT_MAIL_ICS_EVENTS` and `BETTERCAL_LIMIT_MAIL_BODY_CHARS`.

An email Better-Cal can't read, or that's too large, gets an **An email couldn't be read** notice in Review, with its sender and subject when those could be made out. Check the original and add the event by hand, or forward a corrected copy. The notice stays until you dismiss it; past 20, the rest are counted in one notice, and **Show older notices and decisions** reaches ones past the first page. An ordinary email with no event in it isn't a failure and gets no notice.

One bad message never holds up the mail behind it: it's set aside as unreadable rather than retried forever. A lost connection is different, and that message is simply tried again later.

## Limits on Volume and AI Calls

Per-message limits aren't enough when a lot of mail arrives, so there are also caps, in every mode, on new emailed events per day (overall and per claimed sender), on emailed events active at once, on open emailed decisions in Review, and on paid Gemini calls per hour and day. [Scale and limits](limits.md#limits-you-can-change) has the numbers. The account-wide caps can't be reset by changing the From, Message-ID or calendar UID, since the sender controls all three. Reaching the AI limit doesn't stop the other ways of reading mail, and no limit stops you deciding changes to an event already on your calendar. Unreadable-email notices stay visible even when Review's queue of decisions is full.

When a limit is reached, the email is marked read, stays in your mailbox, and adds nothing. Review shows one **Email automation paused** notice per reason, with how many emails were affected and the latest one's subject and sender; dismissing it changes nothing on your calendar. Mail already handled isn't processed again on its own, so if a real burst of bookings hit the limit, add the missing events by hand, forward fresh copies later, or raise the `BETTERCAL_LIMIT_MAIL_*` settings (within their maximums).

## Invitations, Changes and Cancellations Wait for You

A new invitation follows **Add events from email**. Added automatically or with **Add**, it lands as a Maybe and nothing is sent. **Add and accept** in Review also sends your acceptance, when Better-Cal has a way to reply. Once an invitation is on your calendar, later updates are checked against its organizer's address.

An update or cancellation to an invitation already on your calendar is never applied automatically, even from an approved sender. It waits in Review showing the old and new values, and you accept or dismiss it. Some are refused outright and never reach Review: one that claims to update an event that was never an emailed invitation (or is a Google one), doesn't come from its organizer, or is older than the version you have. One that wouldn't change anything visible is skipped.

When the organizer changes or cancels one occurrence of a repeating invitation, Review names that day, and accepting changes only that occurrence. A later change to the whole series keeps the day you already changed.

A sender can claim any version number, so Better-Cal only trusts the numbers of versions you've accepted. If several different updates are waiting, they're shown side by side, so a forged "newer" one can't hide the genuine update. Accepting checks the event as it is right now; if it changed since you looked, you're asked to look again. Dismiss leaves your calendar alone, and deleting the event clears its waiting updates.

Checking the organizer's address isn't proof of who sent a message either. Email signatures (DKIM) could help someday, but forwarding often breaks them, so they can't replace your review.

## RSVP

Only an iMIP REQUEST is an invitation with someone to answer. PUBLISH, structured bookings, calendar links and extracted reservations are bookings, with no reply buttons. Forwarding doesn't always keep the calendar attachment, so check whether an event came through as an invitation or a booking before expecting reply controls.

On an invitation, **Accept**, **Maybe** and **Decline** send an iMIP reply when the server has a sending address that's on the guest list (never the organizer's own). If there's no way to reply, the event says why. Accept makes the event Planned; Maybe and Decline leave it as Maybe. Changing Planned or Maybe by hand sends nothing. Invitations on a connected Google calendar are answered at Google instead ([Google invitations](google-calendar.md#invitations)).

Replies count against the same outgoing-email limits as reminders. If you've hit the limit, your answer is still saved here and the event says plainly that no reply went out. Giving the same answer again sends nothing new. If sending was interrupted after the reply may have gone out, the event says Better-Cal can't know whether it arrived; **Retry** asks before sending another copy, and never resends on its own. A failed attempt still counts against the limit.

If invitations arrive for a different address from the main notification account, set up a separate RSVP sending account so replies come from the address that was invited. Without it, replies use the main SMTP account. For Gmail, use an app password (Google Account, Security, 2-Step Verification, App passwords):

```text
BETTERCAL_RSVP_SMTP_HOST=smtp.gmail.com
BETTERCAL_RSVP_SMTP_PORT=587
BETTERCAL_RSVP_SMTP_USER=you@gmail.com
BETTERCAL_RSVP_SMTP_PASS=<app password>
BETTERCAL_RSVP_SMTP_FROM=you@gmail.com
```
