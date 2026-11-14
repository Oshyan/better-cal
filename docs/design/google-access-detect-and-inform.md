# Google access: detect and inform

This model supersedes [google-access-and-leave.md](google-access-and-leave.md). The owner decided on 2026-11-13 that Better-Cal will not work around permissions lowered at Google. It reads the access level, tells the person when it drops, and runs the calendar read-only until it is back: "We do not need to try to handle every scenario in BetterCal in a way that tries to make up for the faults or config changes of remote systems." That drops "Leave as Google has it" and every repair or closing done on Google's behalf; the two independent reads failed those, and nothing of them is carried over. He then asked that a change never be refused on an old reading: "if someone genuinely attempts a change, even if they have not dealt with the review queue item, that change should trigger a recheck". So the level is read at the moment of every change as well as by the poll, from a request the change already makes wherever one exists. Revised the same day.

Amended on 2026-11-13, before the build, for three things the owner settled or a live run proved: every Review item about access is dismissible ("making the review item dismissible is a must. All review items must be dismissible regardless of a read-only calendar or whatever. The dismiss is not, I've dealt with it necessarily. It's, I don't care about it or I acknowledge this."), §3; a single event read through a list by its iCalUID is proven on real Google, §2; and the stand-in answers by level exactly as the access probe's rows show, §6.

A live probe of real Google (2026-11-13, a calendar shared from a second account: `workfolder/google-access/access-a49ac66229.md`, untracked) settles what Google answers in each access state. A second live run the same day (`workfolder/google-access-listread/access-61fb838522.md`, untracked; stage listread of the same probe, on a calendar the account owns) settles what a list by iCalUID returns against a GET by id. Code references are to the fix branch (`worktree-agent-a005dbfba679dfc71`, `9496fe08`).

## 1. The rule, and what is stored

**Rule.** A change to a Google calendar is sent only when the access level Google states on a list answer, read in that change's own first request, is `writer` or `owner`. The poll keeps the stored level current between changes.

**What is stored.** One existing column, `calendars.google_access_role`, holding the level Google last stated. No new column or form.

**What the probe shows Google answers:**
- `accessRole` is on every events list answer, plain and delta (rows 3, 4, 4d), with the values `writer`, `reader` and `freeBusyReader`.
- Any change of access invalidates the sync token: the next delta answers 410 `fullSyncRequired` (row 4d).
- When sharing is removed, every list answers 404 (stage unshared, rows 3-4d).
- A single event's GET carries no level.

**Who reads it, and where it is stored:**
- **The poll** stores the level of its own list answers, in its own transaction, after the account's check and only while nothing moved since its fetch began (§1, ordering). The read of the whole calendar list is gone. At busy times only it reads one page and applies nothing (§8, round 2).
- **Every change** reads it from its own first request, a list (§2).
- **A list answered 404** means `none`: no longer visible. (Amended 2026-11-14: a calendar deleted by its owner answers every list 404, its delta too, exactly as one no longer shared (stage deleted), so the two can't be told apart and "no longer shown" is the true sentence for both; a 410 to a list sent with no sync token has never been seen, so nothing depends on it: such an answer stores no level and is a failure.)
- **A listing below reader is not applied** (the owner, 2026-11-13, after the first build found a poll at busy times only erasing titles here). What is compared: the level a list answer states, against reader. At `reader`, `writer` and `owner` a listing shows what Google holds, and is applied as before. At `freeBusyReader` it shows only times: Google leaves out at least the title of a read (access probe, stage freebusy, rows 6a, 6c, 6g) and the description and private properties of a list's items (row 4), so applying it would replace what Better-Cal holds with blanks. What is stored: the level (as for any list answer), and the poll's new sync position. What is not applied: anything the listing says of events; every row here stays exactly as it was, held and Google fields alike, until a listing at reader or above comes. When access returns, any change of access has made the stored sync token invalid (row 4d, stage restored: 410 `fullSyncRequired`), so the next poll reads the calendar whole and catches up. One rule, at each place a list answer is applied to stored events (the poll, and a change's read-backs stored after its write): `GoogleWriter::shows`. A list answered 404 (`none`) has nothing to apply, as before.
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
| Any change, undo, Finish or Put back on a calendar stored below writer, and the preview of a change that would be refused for access | none: refused here before Google | not a request | one small list first (`maxResults=1`). If it says writer, the change goes on as normal (§8, rule A; previews: rule 1 of §9) | 1, only on such a calendar |
| A write Google refuses 403 (not a rate limit), 404, 410 or 412 | read as refused, deleted or changed at Google | not a request | one small list first; below writer refused as read-only; the list unreadable: refused as unconfirmed, nothing changed (§8 rule D, §9 rule 2) | 1, only on such a refusal |

**What a list by iCalUID changes against a GET by id:** a list item is the same event resource a GET returns. Proven on real Google (stage listread, `access-61fb838522.md`):
- A list by iCalUID returns the same resource as a GET by id, field by field, with the same etag, for a timed single event (L1), an all-day one (L2) and one imported with our own iCalUID (L3), with showDeleted true and false alike. The list's etag after a PATCH equals the PATCH answer's, and a PATCH with If-Match on the list's etag is taken (L6).
- `accessRole` is on every such list answer (L1 to L8).
- A second import with the same iCalUID updates the first event (it answers with the first import's id), so no two events come to share an iCalUID that way (L4).
- A deleted single event is listed only with showDeleted=true, cancelled, and a GET of it answers 200 cancelled (L5); every first read lists with showDeleted=true, so a deleted event reads as today.
- A list by an iCalUID that never existed answers 200 with no items, and still carries `accessRole` (L8): the level is read even when the event is missing.
- A series listed by its iCalUID returns its master and its renamed day, each with the etag its GET gives (L7).

So the main doubt is closed. What remains:
- The row's uid must be Google's iCalUID. The poll stores `iCalUID` as the uid, or the event's id when Google gives no iCalUID (`GoogleSync.php:438-440`). For such a row the list finds nothing, so the event is then read by its id, after the list (the level is already known from it): 1 extra, for a case Google's documentation says doesn't arise. Rows made by moving a calendar to Google keep their uid through import (L3), so they are found.
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
- A Review item, the same sentence. There is one per calendar while its level stays below writer; its one action is Dismiss, and it closes by itself when the level returns.
- The calendar shows as read-only, as any calendar that can't be edited does: `editable` comes from `GoogleWriter::writable` (`Calendars.php:692`, `GoogleWriter.php:68`).
- A change attempted here asks Google first (§2). While Google still says read-only, the change is refused and nothing is sent: "'Team' is read-only: your permissions for it at Google no longer allow changes. If you have access, restore full editing there." That sentence is for a calendar stored editable until that refusal; one already stored read-only says what it is: "'Team' is view only at Google, so it can't be changed here." (§8, rule D).
- An undo is handled the same way, with: "This can't be undone while 'Team' is read-only: your permissions for it at Google no longer allow changes. If you have access, restore full editing there."
- A change half done at that moment stays in Review with no action, and its event takes no other change (`GoogleSeries::unrecoverable`, `GoogleSeries.php:3107`; actions at `ReviewController.php:232`): "This change stopped part way, and your permissions for 'Team' at Google are now read-only, so it can't be finished or put back. It stays here, and the event takes no other change, until full editing is restored at Google or you remove the calendar."

**Busy times only** is its own state for what the person reads, because more stops than editing: events stop updating. Everything above holds (read-only, refusals, half-done change), and in place of the read-only sentence, in Activity, the Review item, the poll's status and the settings line: "Google now shows this account only busy times for 'Team', so its events here stay as they were and don't update, and changes and undo are unavailable. If you have access, restore full details and editing at Google." Moving between view only and busy times only is a move like any other: one Activity line, and the item for the state now (a dismissed one comes back, since the state it reported changed). The poll at that level succeeds as a request but is shown as not updating: it stores the level and the sync position, applies nothing, and fails with that sentence, so the calendar's status says it on every poll.

**Edit access given back,** learned by the poll or by a change attempted before it:
- The level is stored and the Review item closes. The change attempted goes through without waiting for a poll, and Finish and Put back are offered again.
- Activity: "Your permissions for 'Team' at Google allow changes again. Changes and undo work here again."

**No longer visible** (sharing removed, or the calendar deleted at Google):
- Activity once, a Review item of its own kind, and the poll's error on every failing poll: "Google no longer shows 'Team' to this account: its owner stopped sharing it, or deleted it. Its events here are kept, read-only, and no longer update. If it is shared with you again, it updates again; otherwise remove it, or keep it as a calendar of your own."
- Changes and undo get the read-only sentences with "Google no longer shows 'Team' to this account" in place of "your permissions for it at Google no longer allow changes".
- Keeping the calendar as your own, or removing it, closes a half-done change by the existing rule (`Calendars.php:435`, `GoogleSeries::closeNeverRunnable`).

**Dismissing.** Every Review item about access is dismissible, as the owner asked: a dismiss means "I acknowledge this", not "this is dealt with", so it changes nothing about the calendar, which stays read-only. A dismissed item does not come back while the level stays in its tier (view only and busy times only are one tier, read-only; not shown is another): the item is opened only when the level moves between tiers, never on a poll or a change that finds the same tier. If the level moves again (edit access given back, then taken away again; or view only, then not shown), a new item opens. A dismissed item is closed by nothing; an open one is closed (as gone) when the level returns to writer, when the calendar is kept as a calendar of its own, or when it is removed. Dismissing reuses the existing dismissal of Google notices (`ReviewQueue::dismissGoogleDifferences`, `POST /review/google-differences/:id/dismiss`, already used by `google_differs` and `google_empty`).

The existing Review kinds, and whether each can be dismissed today (not changed by this model):

| Kind | Dismissible today | How |
|---|---|---|
| `invite_new` | yes | Dismiss |
| `invite_change` | yes | Dismiss |
| `mail_limit`, `model_limit`, `mail_error` | yes | Dismiss |
| `google_differs`, `google_empty` | yes | Dismiss |
| `rsvp` | yes | Dismiss (shown quietly), without answering |
| `proposal` | yes | Dismiss (rejects the plan) |
| `duplicate` | yes, as a decision | "Not the same" |
| `plugin` | yes, as a decision | "Leave off" |
| `subscription` | no | only Keep updating, Keep a local copy or Remove |
| `google_half` | no | only Finish and Put back; with neither runnable (the calendar read-only, its account disconnected) it has no action at all |

The last two do not meet the owner's "All review items must be dismissible"; they are reported for his decision, not changed here.

**The Review item.** Kinds are `ReviewQueue` constants (`ReviewQueue.php:46`, beside `KIND_GOOGLE_EMPTY`), stored in `review_items`, whose `kind` is free text (`VARCHAR(32)`), so no migration is needed. Adding the two kinds, `google_read_only` and `google_not_shown`, takes:
- in `ReviewQueue`: one hold and one close for both, as `holdGoogleEmpty`/`closeGoogleEmpty` do (`ReviewQueue.php:1405`, `:1421`), keyed `google-access:<calendar id>`, called only on a move between tiers. A move closes whatever open item the calendar had and opens the one for the tier it is in now (none for editable). The dismissal above takes the two kinds;
- in `ReviewController`: their listing and count (`:362`);
- on the web Review page: their label and icon (`ReviewPage.js:40-41`);
- in `docs/api-contract.md`: one line each.

## 4. Deliberately not handled

Access changes reach one person a few times a year at most. What is left needs a moment of seconds:
- **The lag after access is given back.** One write was refused 403 a few seconds after editing was restored, while the list already said writer, and passed on a rerun (stage restored, first run, row 6e). It gets today's refusal, reworded (`GoogleWriter.php:611`): "Google refused this change; nothing was changed. If you just got edit access back, try again in a moment." How often: almost never.
- **Access lost between a change's first read and its write** (milliseconds): amended after the independent read (its N4), see §8: a write refused 403, 404, 410 or 412 asks the small list before it is read as Google refusing that change, the event deleted, or the event changed at Google.
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

The stand-in (`tools/google-probe/lib/FakeGoogle.php`) answers by level exactly as the probe's rows show, each cited beside the code, and `tools/google-probe/test.php` checks each row against it (each check fails on the stand-in before this change, which answered every level as a writer):
- **Every list answer:** `accessRole`, plain, by iCalUID and delta (rows 3, 4, 4d; L1 to L8).
- **Any change of level:** invalidates every earlier sync token: the next delta answers 410 `fullSyncRequired` (row 4d at stages writer, reader, freebusy and the first restored); a token given after the last change still answers 200 (row 4d at the second restored).
- **`reader`:** lists and reads 200 (rows 1 to 4, 6a to 6c, 6g); an insert 403 `requiredAccessLevel` "You need to have writer access to this calendar." (row 5); every PATCH 403 `forbidden` (rows 6d to 6h).
- **`freeBusyReader`:** lists 200 (rows 3, 4); a single event, a day and another's event 200 with no title (rows 6a, 6c, 6g); a series' main record 404 on read and on PATCH (rows 6b, 6e); other PATCHes 403 `forbidden` (rows 6d, 6f, 6h); an insert 403 `requiredAccessLevel` (row 5).
- **`none`:** 404 on the calendar list entry, the calendar, every list and an insert (rows 1 to 5), and on every read and PATCH (rows 6b, 6c, 6e to 6h), except a single event this account made: it reads 200 (row 6a) and its PATCH with If-Match answers 412 (row 6d). A series this account made answers 404 like the rest (rows 6b, 6e), so the exception is a single event only.
- **Not probed, answered as the nearest proven request and said so in the code:** a DELETE (as a PATCH of the same target), a series' instances list (as a list).

The brief 403 after access returns (stage restored, row 6e) stays out of the stand-in: it is not reproducible.

## 8. Amended after the independent read of the build (2026-11-13)

Four rules, each applied wherever its case arises, from the read's findings (its probes are the regular tests `server/tests/db/access-read-*`).

**A. A refusal for access stored below writer is always preceded by asking Google, and the person can always ask.** Every place that refuses on the stored level asks the one small list first (`GoogleWriter::recheck`) and goes on when Google says writer: an edit, a delete, a new event, the previews of every change (one rule for previews: each starts a change), an undo, and Finish and Put back (`SeriesPlans::recoverTx`, which refused on the stored level before). The web app hides every change control on a calendar that is not editable, so a check would be reachable only through undo and an invitation answer; Check again is the person's own way to ask: a quiet control on the Review item of both access kinds and beside the settings line of a Google calendar below writer, one small list (`Events::checkGoogleAccess`, through `accessNow`, stored by `settleAccess`), its result said in a toast ("Still view only at Google, so nothing changed here."; "Google lets this account make changes now; changes work here."). What triggers a check: the next poll, Check again, an undo, an answer, and any change attempted through the API.

**B. A read made below reader is never kept**, whichever read it is: the poll's listing, a change's read-backs stored over the rows (`GoogleSeries::store`) and the read a stopped change records as what Google held for Put back to compare with (`$settled` in `run`), each asking `GoogleWriter::shows` of the level its list gave.

**C. The store says every tier it learns, and unknown is not view only.** (Narrowed by §9.) A level learned when none was stored (a calendar added before levels were kept, or paused by the compromise reset) closes any stale item and writes its Activity line, in words that say nothing about before ("'Team' is view only at Google, so it can't be changed here."; "Google doesn't show 'Team' to this account ..."; "Your permissions for 'Team' at Google allow changes."). The settings line for a calendar whose level is not known says so and that it is read at the next check.

**D. A sentence is derived from the state, never from the path.**
- Whether anything was changed is known from what the change sent: each refusal carries its reason without "nothing was changed" (`said`), and a change that had sent part of itself says that reason, then that part of it reached Google; Google's own reason text rides along on a permission refusal.
- "No longer", "now" and "restore" are said only where the stored level before was writer: a calendar that could not be changed here says what it is ("'Team' is view only at Google, so it can't be changed here."; an undo: "This can't be undone: 'Team' is view only at Google.").
- A write refused 403 (not a rate limit), 404, 410 or 412 is read only after the one small list says the level now; below writer it is refused as read-only, never as Google refusing that change, the event deleted ("already removed" for a delete) or changed at Google. Cost: one list, paid only on such a refusal, never on a write Google takes.
- A DELETE refused 404 at not shown is the second round's answer for every target (below); it gets the small list like any refused write.

**The second live round (2026-11-14, `workfolder/google-access-2/access-a49ac66229.md`, untracked; the manager's reading `workfolder/reviews/google-156/access-probe-round-2.md`) settles five things, each followed:**
- Every DELETE with If-Match at view only and at busy times only answers 403 forbidden (the account's own single event, a day's record, a series and another's event alike; rows D1 to D4), and at not shown 404 notFound with every event still there afterwards. So a 404 on a DELETE is never "already deleted" until the small list has answered (rule D above). The stand-in answers so; its DELETE is no longer unproven.
- A busy-only listing is another shape, never a thinner one: its items carry only end, etag, htmlLink, iCalUID, id, kind, start, status, updated and visibility; a series is listed as its occurrences with no rule (4 items where writer gets 2, U2); the listing ends with no sync token (row 4). Nothing in it says what an event is, so it can never be applied: there is no title, notes, place, rule or edited day to apply. The poll reads its first page, stores the level, keeps no sync position (Google gives none) and applies nothing; each poll at that level reads one page; the first after access returns reads the calendar whole.
- An insert under an id that exists: 409 at writer, 403 requiredAccessLevel at view only and busy times only, 404 at not shown (row I): the level is checked before the id, so the small list after a refused insert reads it right.
- The calendar list entry stays 404 after access is given back while events answer 200 (row 1, stage restored): reading the level from the events list, never the calendar list, is right.
- Which of the account's own events stay readable at not shown differs between the rounds (round 1: the single event 200, its PATCH 412, the series 404; round 2: the single event 404 with its PATCH still 412, the series 200). Nothing is built on it: every write refused at any level asks the small list, and the stand-in's case is marked unproven and contradicted.

**Known, not built: mixed answers in the seconds after access is given back.** Round 2, stage restored: a PATCH was taken 1.1 seconds in, but seconds later an insert answered 403 requiredAccessLevel and one list by iCalUID answered 404 while five others answered 200. For a 403 on an insert whose small list then says writer, the sentence stays right: "Google refused this change; nothing was changed. If you just got edit access back, try again in a moment." For two list answers in one request, the store takes the last: a 404 that comes after a writer answer stores not shown, says so once, and refuses the change as not shown; the next poll or attempt (seconds later) stores writer again and says so. Deciding between the answers (the highest level of the request, say) would be new mechanism for a window seconds wide; not built.

**Known, not built: a change's older read stored after a newer poll's (the read's N5).** A change's level is stored when its transaction ends; a poll that fetched later but committed first is overwritten by the change's older word. Two connections, seconds wide, and the next poll or attempt corrects it. Not worth a mechanism.

## 9. Amended after the second independent read (2026-11-14), the branch's last fix round

**1. A preview asks Google exactly where its commit would refuse for access, and nowhere else** (replaces "every preview asks", which refused marks from a day on on any view-only calendar). One reading of "can be refused for access": an edit with a key Google holds (`Events::googleKeys`: not tags, people, reminders, an answer or attendance mark, nor the request's own scope, day, plan or preview). Pairs:

| Preview | Its commit | Asks / can refuse |
|---|---|---|
| `previewPatch` (all events, this and following) | `patchLocked` | both when `googleKeys` is not empty; neither otherwise |
| `previewDelete` (this and following) | `deleteEventLocked` | both |
| `previewAttendance` (an answer or mark from a day on) | the attendance commit | neither: held here alone |
| a split's preview of an edit (`previewSplit`, edit) | `patchLocked` | both when `googleKeys` is not empty |

**2. A refusal built after the small list is a refusal.** Google answered the write with a 4xx and took nothing, so the step is not journaled as sent, the change is not listed half done, and the sentence does not say part of it reached Google (`GoogleSeries::refused` counts the access rule's refusals and the unconfirmed one). When the small list itself cannot be read, the refused write is read as nothing: refused as unconfirmed with the words `admit` uses ("Google did not say what this account may do with this calendar, so nothing was changed. Try again in a minute."), never a DELETE's 404 as "already removed".

**3. A dismiss acknowledges one state and holds nothing else.** Health mail is held only for the failure the dismissed notice reports, while that state holds: a poll at not shown or busy times only records its own sentence, a calendar listing nothing its own, a paused feed its reason; any other failure of the calendar, and the calendar in another state, alert as before. View only: its polls succeed, so its notice holds nothing.

**4. What a dismiss hides stays reachable while its state holds.** Review's history (status all) lists every dismissed notice whose state still holds, with "Show again", one rule for every notice kind, `subscription` and `google_half` included (a half-done delete of a whole series leaves no event to carry the line).

**C, narrowed.** A level learned when none was stored closes any stale item and writes the Activity line; it opens a Review item only below what the calendar was last known to allow. Nothing about before survives the compromise reset (it clears the level), so after a reset no item opens; the Activity line and the settings line carry it.

**Wording.** Check again says "still" only when the level did not move ("Google now shows this account only busy times, so its events here stay as they were and don't update."); the busy-only settings line has no "now"; the event's line says "stopped part way" once when the item's own sentence is joined to it.

**Known, open doubt (N2).** In the seconds after access is given back, a change whose write Google took and whose last list answers 404 stores "not shown" until the next poll or Check again (§8). The one live 404 of this kind was a list by one series' iCalUID (round 2, stage restored); it could not be run again, the scratch calendar no longer exists. The live case that would settle it: give access back, then within ten seconds list one series by its iCalUID and the calendar plainly, several times each, and see whether the 404 is per series or per calendar.

## 7. Dependence on the fix branch

It depends on nothing still being built there. It does change code that branch is reworking:
- `GoogleSeries::condition`;
- `GoogleUndo`'s first reads and its refusal sentences;
- the pending-row code beside `unrecoverable`.

So it is built after that branch merges, on top of it.
