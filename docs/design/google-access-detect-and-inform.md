# Google access: detect and inform

This model supersedes [google-access-and-leave.md](google-access-and-leave.md). The owner decided on 2026-11-13 that Better-Cal will not work around permissions lowered at Google. It reads the access level, tells the person when it drops, and runs the calendar read-only until it is back: "We do not need to try to handle every scenario in BetterCal in a way that tries to make up for the faults or config changes of remote systems." That drops "Leave as Google has it" and every repair or closing done on Google's behalf; the two independent reads failed those, and nothing of them is carried over. He then asked that a change never be refused on an old reading: "if someone genuinely attempts a change, even if they have not dealt with the review queue item, that change should trigger a recheck". So the level is read at the moment of every change as well as by the poll, from a request the change already makes wherever one exists. Revised the same day.

A live probe of real Google (2026-11-13, a calendar shared from a second account: `workfolder/google-access/access-a49ac66229.md`, untracked) settles what Google answers. Code references are to the fix branch (`worktree-agent-a005dbfba679dfc71`, `9496fe08`).

## 1. The rule, and what is stored

**Rule.** A change to a Google calendar is sent only when the access level Google states on a list answer, read in that change's own first request, is `writer` or `owner`. The poll keeps the stored level current between changes.

**What is stored.** One existing column, `calendars.google_access_role`, holding the level Google last stated. No new column or form.

**What the probe shows Google answers:**
- `accessRole` is on every events list answer, plain and delta (rows 3, 4, 4d), with the values `writer`, `reader` and `freeBusyReader`.
- Any change of access invalidates the sync token: the next delta answers 410 `fullSyncRequired` (row 4d).
- When sharing is removed, every list answers 404 (stage unshared, rows 3-4d).
- A single event's GET carries no level.

**Who reads it, and where it is stored:**
- **The poll** stores the level of its own list answers, in its own transaction, where it stores a role today (`GoogleSync.php:178`). Today's read of the whole calendar list (`GoogleSync.php:71-79`) goes.
- **Every change** reads it from its own first request, a list (§2).
- **A list answered 404**, or 410 where no sync token was sent, means `none`: no longer visible.
- **Two ordering rules:**
  - Nothing is stored across the compromise reset. The account is checked under its lock first: the poll's `assertUsable($account, true)` at `:177`, and the same check before a change's store (the second read's F11).
  - An older answer never overwrites a newer one (the second read's F10). The poll stores its level only when neither the stored level nor the sync position moved while it fetched. A change's read is always the newest word, and is stored once that change's transaction is over, whatever it did (a refusal rolls it back).

## 2. The read that comes before every change

What each kind of write sends first today, and what it becomes:

| Kind of write | First request today (code) | A list? | Becomes | Extra requests |
|---|---|---|---|---|
| Edit, drag or delete of an event in no series | `GoogleSeries::condition` (`GoogleSeries.php:617`) → `readBack` by id: GET one event (`:1129`) | no | the same `readBack` by its iCalUID, as a series is read (`:1139`): one list, the item picked by id | 0 |
| One day of a repeating event (edit, drag, delete, "use series value") | `condition` → `readBack` by the series' iCalUID (`:627`, `:1139`) | yes | unchanged. A day with no record of its own is then read by its instance id for an etag (`:641`), after the list | 0 |
| A change to all events, "this and following", delete from a day on, a new repeating event's later changes | `GoogleSeries::apply` reads every series it touches through `condition` before its first step | yes | unchanged | 0 |
| A new event, or a new series (an insert; a split's new series is inserted only after the old series' list) | none: the POST is the first request (`Events.php:449`) | not a request | unchanged. On success the level was writer. On a refusal (403 or 404) one small list is asked, so the person gets the true reason and the level is stored | 0, or 1 on a refusal |
| An answer to an invitation | `Rsvp` GET of the event (`Rsvp.php:406`), then `patch` → `condition` | no | the GET becomes the iCalUID list (a recurring invitation is already read by its series' list in `condition`) | 0 |
| An undo or redo of one event or one day | `GoogleUndo` GETs (`GoogleUndo.php:491`, `:497`, `:541`, `:622`), then `patch` or `delete` → `condition` | no | the first request becomes the iCalUID list of the event or its series. Instance-id GETs follow it | 0 |
| An undo of a change to a whole series | `apply` (`GoogleUndo.php:724`) → `condition` | yes | unchanged | 0 |
| Finish and Put back | `GoogleSeries::refresh` reads every series by list (`:3975`) | yes | unchanged | 0 |
| Any change or undo on a calendar stored below writer | none: refused here before Google (`Events.php:1315`, `:1699`, `:2210`; `GoogleUndo.php:451`, `:693`) | not a request | one small list first (`maxResults=1`). If it says writer, the change goes on as normal | 1, only on such a calendar |

**What a list by iCalUID changes against a GET by id:** a list item is the same event resource a GET returns. Two things to settle:
- It needs the row's uid to be Google's iCalUID. The poll stores `iCalUID` as the uid, or the event's id when Google gives no iCalUID (`GoogleSync.php:438-440`). For such a row the list finds nothing, so it keeps today's GET, after one small list for the level: 1 extra, for a case Google's documentation says doesn't arise. To be confirmed for rows made by moving a calendar to Google, whose uid Google keeps through import.
- It answers where a GET does not: the probe's busy-only stage answers 404 to a series' main record by GET (row 6b) while lists answer 200 with the level (rows 3, 4). So the list is the safer first read.

Nothing else is lost that the code uses.

**The decision, in one place.** `GoogleSeries::condition` is the read every PATCH and DELETE already goes through (`GoogleWriter.php:381`). It reads the level from its list answer:
- `writer` or `owner`: the change goes on.
- Anything below: the change is refused with the access sentence and nothing is sent. The level is stored, and Activity and Review are told once (§3).
- A list answered 404: the calendar is no longer visible, and the change is refused the same way.

An insert's refusal and the stored-below-writer path ask the small list and decide the same way.

**Is a 404 still read as "deleted" without the level in the same request?** Only in two places:
- A write's own answer (the PATCH or DELETE itself, or an insert) in the moment between its list and itself.
- A GET by instance id that follows the list within the same change, a moment after that list said writer.

Every first read is a list, and an event missing from a list that answered 200 with a level is the one meaning of "deleted at Google". This closes the window of the earlier draft: a delete on a calendar whose access was just lowered or removed is refused on its first read and loses nothing.

## 3. What the person reads

The owner's wording is the base. `'Team'` stands for the calendar's name.

**Edit access lowered to view only, or busy times only** (learned by the poll or by a change's first read):
- Activity, once: "Your permissions for 'Team' at Google are now read-only. If you have access, restore full editing there. Until then 'Team' works read-only here: changes, and undoing past changes, are unavailable."
- A Review item, the same sentence. There is one per calendar while its level stays below writer, with no action, and it closes by itself when the level returns.
- The calendar shows as read-only, as any calendar that can't be edited does: `editable` comes from `GoogleWriter::writable` (`Calendars.php:692`, `GoogleWriter.php:68`).
- A change attempted here asks Google first (§2). While Google still says read-only, the change is refused and nothing is sent: "'Team' is read-only: your permissions for it at Google no longer allow changes. If you have access, restore full editing there."
- An undo is handled the same way, with: "This can't be undone while 'Team' is read-only: your permissions for it at Google no longer allow changes. If you have access, restore full editing there."
- A change half done at that moment stays in Review with no action, and its event takes no other change (`GoogleSeries::unrecoverable`, `GoogleSeries.php:3107`; actions at `ReviewController.php:232`): "This change stopped part way, and your permissions for 'Team' at Google are now read-only, so it can't be finished or put back. It stays here, and the event takes no other change, until full editing is restored at Google or you remove the calendar."

**Edit access given back,** learned by the poll or by a change attempted before it:
- The level is stored and the Review item closes. The change attempted goes through without waiting for a poll, and Finish and Put back are offered again.
- Activity: "Your permissions for 'Team' at Google allow changes again. Changes and undo work here again."

**No longer visible** (sharing removed, or the calendar deleted at Google):
- Activity once, a Review item of its own kind, and the poll's error on every failing poll: "Google no longer shows 'Team' to this account: its owner stopped sharing it, or deleted it. Its events here are kept, read-only, and no longer update. If it is shared with you again, it updates again; otherwise remove it, or keep it as a calendar of your own."
- Changes and undo get the read-only sentences with "Google no longer shows 'Team' to this account" in place of "your permissions for it at Google no longer allow changes".
- Keeping the calendar as your own, or removing it, closes a half-done change by the existing rule (`Calendars.php:435`, `GoogleSeries::closeNeverRunnable`).

**The Review item.** Kinds are `ReviewQueue` constants (`ReviewQueue.php:46`, beside `KIND_GOOGLE_EMPTY`), stored in `review_items`, whose `kind` is free text (`VARCHAR(32)`), so no migration is needed. Adding the two kinds, `google_read_only` and `google_not_shown`, takes:
- in `ReviewQueue`: a hold and a close function each, as `holdGoogleEmpty`/`closeGoogleEmpty` do (`ReviewQueue.php:1405`, `:1421`), keyed `google-access:<calendar id>`. Moving between the two closes one and opens the other;
- in `ReviewController`: their listing and count (`:362`);
- on the web Review page: their label and icon (`ReviewPage.js:40-41`);
- in `docs/api-contract.md`: one line each.

## 4. Deliberately not handled

Access changes reach one person a few times a year at most. What is left needs a moment of seconds:
- **The lag after access is given back.** One write was refused 403 a few seconds after editing was restored, while the list already said writer, and passed on a rerun (stage restored, first run, row 6e). It gets today's refusal, reworded (`GoogleWriter.php:611`): "Google refused this change; nothing was changed. If you just got edit access back, try again in a moment." How often: almost never.
- **Access lost between a change's first read and its write** (milliseconds): the write's own 403 gets the sentence above, and its own 404 is read as today's "deleted at Google". How often: almost never.
- **Deleted against unshared.** Google gives no way to tell them apart, and the probe did not try a deletion. Both get the "no longer shows" sentence, which names both.
- **Moving a calendar to Google** (`GoogleMove`): it writes into a calendar that is not yet a Google calendar here. Out of this model.

## 5. Size, and what of the stopped branch is used

Built on the fix branch. Reused from the stopped branch (`worktree-agent-a0010f1129f7ead48`):
- `GoogleWriter::writes` and `GoogleWriter::roleOfList`, from `dfa6136d`;
- the move-between-tiers Activity lines of `GoogleWriter::learnAccess`, reworded and given the two ordering rules of §1;
- `GoogleWriter::accessNow` as the small list (one list, no calendar-list read);
- the stand-in's `accessRole` on list answers, from `dfa6136d`.

Dropped:
- `asksAccess`, `accessLost` as written, `unconfirmed`, `accessLostPartWay`, `accessLostWhy`, and the confirming request after every refusal in `call`;
- `gone`'s access exclusion, and the three readers changed to ask it;
- all of Leave and its tests, the loss naming in `closeNeverRunnable`, and the probe files built on the stopped build.

What gets built:

| Where | Change |
|---|---|
| `GoogleSeries::condition` and `readBack` | the first read is a list, and the level is decided there |
| `GoogleWriter` | the level from list answers, a list's 404 as not visible, the insert's refusal, the small list, the stored-below-writer path |
| `GoogleSync` | the poll stores the level, with both orderings |
| `Rsvp`, `GoogleUndo` | their first GET becomes the iCalUID list; `GoogleUndo` also gets two sentences |
| `Events` | the read-only gates ask the small list before refusing |
| `GoogleSeries::unrecoverable` | its sentences |
| `ReviewQueue`, `ReviewController`, the web Review page | the two Review kinds |
| the stand-in | its answers by role |
| tests | one database test file and one frontend test |

About eleven server files and one web file, most changes a few dozen lines. The largest is `condition`, plus the ordering of `GoogleUndo`'s first reads, which sit in undo code another worker is reworking.

## 6. The check

The sentences a test follows from, each computed from the test's own record of what the stand-in was set to and when, never from the product:
- After every poll and every change, the stored level is the level of the last list answer the stand-in gave, `none` after a 404.
- A change is sent to Google only if its own first request was a list that answered `writer` or `owner`. Otherwise it is refused with its sentence, nothing is sent, and nothing held here is lost.
- Activity has one line per move between tiers, and exactly one Review item is open per calendar below writer.

The cases it must cover:
- a change attempted minutes after access was lowered or removed, before any poll: refused on its first read, with tags, people and reminders on the event unchanged;
- a change attempted after access was given back, before any poll: it goes through, and the Review item closes.

The damage it must catch:
- a write sent after a read below writer, or with no list read first;
- a delete on a calendar no longer visible completed as "already deleted";
- an older poll answer stored over a newer change's read;
- a level stored across the compromise reset;
- the level not stored on the 410 path;
- a missing or repeated Activity line or Review item;
- a Review item left open after access returns;
- a half-done change offered an action while read-only.

The stand-in must match the probe:
- **Every list answer:** `accessRole`, plain and delta (rows 3, 4, 4d).
- **Any change of role:** invalidates earlier tokens with 410 `fullSyncRequired` (row 4d).
- **`reader`:** reads 200; insert 403 `requiredAccessLevel`; patch 403 `forbidden` (rows 5, 6d-6h).
- **`freeBusyReader`:** lists 200; single events and days 200 without a title; a series' main record 404 on read and patch; other writes 403 (stage freebusy).
- **`none`:** 404 on every list, get, insert and patch, except an event the account created: it reads 200, and its patch answers 412 (stage unshared, rows 6a, 6d).

The brief 403 after access returns (stage restored, row 6e) stays out of the stand-in: it is not reproducible.

## 7. Dependence on the fix branch

It depends on nothing still being built there. It does change code that branch is reworking:
- `GoogleSeries::condition`;
- `GoogleUndo`'s first reads and its refusal sentences;
- the pending-row code beside `unrecoverable`.

So it is built after that branch merges, on top of it.
