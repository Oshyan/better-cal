# Email ingest: mail becomes calendar events

The worker checks the ingest mailbox about every two minutes. It reads calendar attachments, booking details and invitation links, then adds events to the local Invitations calendar or holds them in Review, according to your choice in Settings, General.

## Choose what gets added

**Add events from email** has three choices:

- **From any sender:** add every new event the reader recognizes. An invitation is added unanswered, as Maybe. No reply is sent.
- **From approved senders:** the default for new and existing installs. Events from approved addresses or domains are added automatically; other events wait in Review.
- **Hold everything for Review:** every new emailed event waits for you to add or dismiss it.

The choice applies to invitations as well as bookings, regardless of how the event was read. It does not change how updates and cancellations to invitations already on your calendar are handled.

An event from a sender you haven't approved waits in Review, with three ways to add it:

- **Add** adds just this one.
- **Always add from bookings@example.com** adds it and approves that address from now on.
- **Always add from example.com** adds it and approves every address at that domain, handy when a merchant sends from several addresses.

None of them sends a reply. The domain button doesn't appear for shared mail providers like Gmail, Outlook, iCloud and Yahoo, where you can only approve one address at a time, since approving the whole domain would let in everyone who uses it. Better-Cal's list of those providers can't name every small one, so approve a domain only when you mean everyone who can send from it.

Settings lists your approved senders, and you can remove any of them there. Through the API you can also add addresses, domains and wildcards like `*.example.com` (which covers `mail.example.com` and the like, but not `example.com` itself). Editing the whole list in one go is planned (issue #151).

When you update to 0.9.87, every sender whose events were added in the last 90 days is approved for you, as an exact address, so merchants you already use keep working. A sender you last heard from longer ago than that needs one approval next time. Changing the mode or approving a sender doesn't release events already waiting in Review; add or dismiss those yourself.

Approving a sender is a convenience, not proof of who sent a message: anyone can put any address in the From line. So keep the ingest address private; an alias you never give out makes it harder to guess. If you give the address straight to a booking service, keep **From approved senders** on and approve the service after checking its first event.

## How messages are read

The reader tries these sources in order:

1. A `text/calendar` part or `.ics` attachment, including iMIP invitations and PUBLISH bookings.
2. Structured booking or event details in HTML, such as schema.org Event and Reservation data.
3. An **Add to Google Calendar** template link. These often survive forwarding even when structured HTML is removed.
4. Gemini extraction from the subject and body, only when the earlier sources found nothing and the subject looks like an invitation, confirmation, ticket or booking. A body with no date evidence is skipped without a paid call.

Mail is processed one message at a time. The server checks its size before decoding headers or downloading the body. Messages over 5 MiB and calendar parts over 1 MiB or 200 events are not parsed. Text and HTML supplied to extraction are limited to 512 KiB. The original email stays in the mailbox.

An email Better-Cal can't read, or that's too large, gets an **An email couldn't be read** notice in Review, with its sender and subject when those could be made out. Check the original in your mailbox and add the event by hand, or forward a corrected copy. The notice stays until you dismiss it. **Show older notices and decisions** reaches ones past the first page. An ordinary email with no event in it isn't a failure and gets no notice.

One bad message never holds up the mail behind it. Each one is noted before it's read, so a message that can't be read is set aside rather than retried forever, and if reading it ever stops the worker outright, the next run turns it into a notice. A lost connection is different: that message is simply tried again later. Each message is recorded for 90 days so it's never added twice.

## Aggregate safety and paid-model limits

Per-message limits are not enough when many messages arrive. These persistent limits remain in effect in every mode:

- At most 100 new email events or Review candidates in a rolling 24 hours, 25 from one claimed From address, and 500 active email-created events at once. A repeating series counts while it can still produce an occurrence; ended COUNT and UNTIL series stop counting. A moved future occurrence still counts. A repeat rule that cannot be checked produces a visible capacity problem, not a false claim that it ended.
- At most 100 open emailed event decisions in Review. Unreadable-email notices are separate and remain visible even when this queue is full.
- At most 20 mail-reading Gemini calls in an hour, 50 in 24 hours, and 20 in 24 hours for one claimed From address.

From, Message-ID and calendar UID are sender-controlled, so changing them does not reset the account-wide limits. A full model budget does not block deterministic extraction. New-event limits do not stop you deciding changes to an event already on your calendar.

When a limit is reached, the email is marked read, stays in your mailbox, and adds nothing. Review shows one **Email automation paused** notice per reason, with how many emails were affected and the latest one's subject and sender; dismissing it changes nothing on your calendar. If a real burst of bookings hit the limit, add the missing events from your mailbox by hand, forward fresh copies later, or raise the `BETTERCAL_LIMIT_MAIL_*` settings (within their maximums). Mail already handled isn't processed again on its own.

Ranking events from subscribed calendars uses AI too, with its own budget: 40 requests an hour and 200 a day for the account, and 20 an hour and 100 a day per calendar, so one busy feed can't use up everyone's share. When it runs out, Review shows a notice, existing scores stay as they are, and ranking picks up again on its own once there's room. These are separate from the email and prompt-filter budgets.

## Invitations, changes and cancellations wait for you

A new invitation follows **Add events from email**. Added automatically or with **Add**, it lands as a Maybe and nothing is sent. **Add and accept** in Review also sends your acceptance, when Better-Cal has a way to reply. Once an invitation is on your calendar, its organizer's address is what later updates are checked against. (Invitations added before 0.9.87 get that check once you've made a decision about them.)

An update or cancellation to an invitation already on your calendar is never applied automatically, even from an approved sender. It waits in Review showing the old and new values, and you accept or dismiss it. Some are refused outright and never reach Review: one claiming to update an event that was never an invitation, or a Google-managed one, or that doesn't come from its organizer, or that's an older version than the one you already have. A message that wouldn't change anything visible is simply skipped.

When the organizer changes or cancels just one occurrence of a repeating invitation, Review names that day, and accepting changes only that occurrence; the rest of the series stays as it is. A later change to the whole series keeps the day you already changed.

Each version of an invitation carries a number, and a sender can claim any number. So Better-Cal only trusts the numbers of versions you've accepted: if several different updates are waiting, they're all shown side by side, and a forged "newer" one can't hide the genuine update. An occurrence changed on its own keeps its own number, separate from the series'. The same message arriving twice shows once. Accepting checks the event as it is right now; if it changed since you looked, you're asked to look again. Dismiss leaves your calendar alone, and deleting the event clears its waiting updates.

Checking the organizer's address isn't proof of who sent a message. Verifying email signatures (DKIM) could help later, but forwarding often breaks those, so it can't replace your review of changes.

## RSVP

Only an iMIP REQUEST is an invitation with someone to answer. PUBLISH, structured bookings, calendar links and extracted reservations are bookings with no reply buttons. Once an invitation is on the calendar, **Accept**, **Maybe** and **Decline** send an iMIP reply if the server has a configured sending address on its guest list. The organizer's own address is never used as the guest. If a route is missing, the event explains why.

Accept makes the event Planned; Maybe and Decline leave it as Maybe. Changing Planned or Maybe by hand sends nothing. Google calendar invitations are answered at Google instead; see [Google invitations](google-calendar.md#invitations).

Replies count against the same outgoing-email limits as reminders and alerts. If you've hit the limit, your answer is still saved here, and the event says plainly that no reply went out. Only one reply per event can be sending at a time, and giving the same answer again sends nothing new.

If sending is interrupted after the reply may have gone out, Better-Cal can't know whether the organizer got it, and the event says so. **Retry** asks before sending another copy; it never resends on its own. A failed attempt still counts against the limit. If you sign that browser out while a reply is sending, it's checked again just before sending and before saving, but an email already sent can't be recalled.

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

IMAP defaults reuse the main SMTP mailbox host, user and password on port 993. Set `BETTERCAL_IMAP_HOST`, `BETTERCAL_IMAP_PORT`, `BETTERCAL_IMAP_USER` and `BETTERCAL_IMAP_PASS` if the ingest mailbox differs. Better-Cal reads mail with its own library (webklex/php-imap), so PHP's IMAP extension isn't needed.
