# 1.5.7, Slice 1: What a Change to One Google Event Promises

Written 2026-11-14 by the stage 0 reader of the 1.5.7 run, against `main` at 1.5.6 (`3702b514`). This page is what gets built. Where it is simpler than the model below, this page wins (the owner's guidance in the 1.5.7 brief: last write wins while our own change is sent, when in doubt ask, no mechanism for rare cases). Slice 1 only: a change or delete of an event in no series on a Google calendar. One day of a repeating event, the undo of plain fields, and Merge are not in it; they keep today's refusal.

### The cases, most likely first

Each promise names its test: a **row** of the exhaustive `threeWay(base, now, wanted)` table (`server/tests/google-conflict-threeway.php, included by server/tests/run.php`), or a **scenario** of the request-level database test (`server/tests/db/google-conflict-choice.test.php`), which sends client-shaped requests to the stand-in for Google.

1. **Nothing changed at Google since the event was shown.** The change goes through exactly as in 1.5.6: one read, one conditional write, no question, nothing kept. Row: every field G = B. Scenario: "unchanged event: edit, drag, delete, Maybe each send one write; `kept` is empty".
2. **Someone changed a different field at Google** (the organiser moved the time, you fix the title). Your change goes through; Google's value for the other field stays, here and there; the toast says what else changed and that it was kept. Rows: field not in the request, G ≠ B gives `kept`; field in the request, G = B gives `send`. Scenario: "time changed at Google, title changed here: PATCH sends the title alone, the row holds Google's time and your title, `kept` names the time with its value now".
3. **Someone changed the same field at Google, to something else.** Nothing is sent and nothing of the request is written here. You are asked: the field, what Google holds now, what your change would make it, Cancel or Overwrite. The calendar shows what Google holds (the read is stored). Cancel saves nothing and returns you to your change. Overwrite sends your change again and it goes through. Rows: field in the request, G ≠ B and G ≠ W gives `ask`. Scenarios: "same field: 409 `google_changed` with the field, nothing sent, rows hold Google's value, tags in the same request not written"; "Overwrite after the question: one PATCH, rows hold your value".
4. **Both sides made the same change.** No question; it goes through. Row: G = W ≠ B gives `send` (nothing left to send for that field). Scenario: "same value at Google and here: no question".
5. **A poll brought the change in while your editor or the calendar was open.** You are still asked, because the comparison is with what you were shown, not with what the row holds now. Scenario: "poll between serve and save: the request echoes the served title in `over`, the poll stored Google's title, the save asks".
6. **You delete an event that changed at Google.** You are asked: what changed (from what you saw to what it is now), Cancel or Delete. Delete deletes it. Unchanged, the delete goes as today. Rows: delete wants every field, any G ≠ B asks. Scenarios: "delete over a place change asks, lists the place with was and now"; "Delete after the question deletes"; "delete of an unchanged event sends one DELETE".
7. **Planned or Maybe on an event changed at Google.** It is the `status` field like any other: a different field changed at Google is kept. Status has two values, so G ≠ B and W ≠ B means G = W: Planned or Maybe never asks about itself. Rows: the `status` rows. Scenarios: "Maybe over a title change at Google: one PATCH of the status, `kept` names the title"; "Maybe when Google already set it tentative: no question, nothing left to send".
8. **Answering an invitation (an event in no series) that changed at Google.** Never asked about: the click is the choice. What changed at Google is stored and kept; the answer is sent. If the answer itself was changed in Google Calendar, your click wins. Scenario: "answer over a title change at Google: one PATCH of the guest list, the row holds Google's title, `kept` names it".
9. **The setting is off** (on as shipped; off is an operator's switch for one account): exactly 1.5.6, `google_conflict` with `GoogleWriter::CONFLICT_MESSAGE`. Scenario: "setting off: the 1.5.6 refusal for each of the above"; and `google-etag.test.php` passes unchanged.

### Unlikely: what the person sees, and nothing more is built

- **Google changed again between the question and Overwrite:** the question comes back with the newer value (the same rule, no new code). Scenario: "second change before Overwrite asks again".
- **Notes that differ only in formatting or spelling** (a non-breaking space, markup Google adds): may ask once about notes nobody changed; one extra Cancel or Overwrite. No normaliser unless probe P2 shows it happens on every round trip.
- **The event was deleted at Google:** an edit gets 1.5.6's `google_deleted` sentence; a delete completes, as in 1.5.6.
- **A single event made repeating at Google:** 1.5.6's refusal (`google_conflict`, `CONFLICT_MESSAGE`), nothing stored. The rule is not in the field list.
- **A change at Google in the seconds between our read and our write:** Google's 412, 1.5.6's `google_conflict` sentence. Otherwise the last write wins; nothing guards that window.
- **A zone-only change at Google:** stored, nothing said; no instant moved.
- **View-only or busy-only access:** 1.5.6's access refusal before anything is read or stored.
- **A tab left open from 1.5.6** sends no `over`, so it is never refused: its changes are read against the event as stored, as 1.5.6 read them (T3), and it shows no question until reloaded (a 409 `google_changed` reaches it as the server's own sentence). Only a page that sends `over` with a time in parts (a drag's start and end alone) or an `over` without its zone gets 400 `client_outdated`, nothing read or written, until it reloads (b); no released version does.
- **An API caller that sends no `over`:** the base is the row, so a change a poll already stored is not asked about. Documented in the API contract.
- **Review applying an invitation's update, a plugin's run, an agent token:** no dialog; the 409 carries the whole sentence; Review keeps the item open.
- **Deleting a trip with its events, one of which asks:** the question for that event; Cancel stops there, and the toast says which were already deleted.

### The contract builders share

**(a) The field list and its equalities.** Six fields, each compared by equality after the one normaliser that already exists, `GoogleSeries::fieldsOf` (GoogleSeries.php:1649); nothing else is added.

| Field | Columns | Equal when |
| --- | --- | --- |
| `title` | `title` | trimmed strings equal; "(No title)" equals empty |
| `description` | `description` | `Sanitize::description` of each equal; null equals empty |
| `location` | `location` | trimmed strings equal; coordinates never compared |
| `url` | `url` | `Ics::webUrl` of each equal; null equals empty |
| `status` | `status` | both tentative or both not |
| `time` | `start_utc`, `end_utc`, `all_day` | all three equal; the zone is not compared |

The check writes its own equalities from this table and calls nothing in `server/src`.

**`threeWay`.** A new pure class, `server/src/Domain/GoogleConflict.php`: `GoogleConflict::threeWay(array $base, array $now, array $wanted): array`, one entry per field of the list. `$base` and `$now` are full maps; `$wanted` holds only the fields the request sets whose value differs from base (a field sent at its base value is not wanted, and is dropped from what is sent). Outcomes: not wanted and now = base: `same`; not wanted and now ≠ base: `kept`; wanted and now = base or now = wanted: `send`; wanted and now ≠ base and now ≠ wanted: `ask`. A delete passes every field as wanted with a value no map can hold. The name `GoogleSeries::threeWay` is taken (a private helper of the series outcome words, GoogleSeries.php:2582), hence the new class.

**(b) Where `base` comes from.** For each field, the value in the request's `over`, parsed exactly as the same key in the request body is (`Events::columnPatch` over the row, Events.php:2909), else the row's value. `over` uses the keys and forms the API serves for the event (`title`, `description`, `location`, `url`, `status`, and the time as `start`, `end`, `allDay` and `tzid`, as `serializeFields` gives them, Events.php:2696). The app sends `over` for every field it changes, from the record or occurrence it showed, never from the editor's form. A unit test pins serve-then-parse as the identity for each field. Why not the row alone: a poll can store Google's change while the editor is open, and the row would then make the person's change a silent overwrite (case 5). **A time is one value** (the owner's decision, 2026-11-14, after the second outside read). T1: a time is its start, end, all-day setting and zone, together; the clock-time flag (`floating`) is a field of its own, not part of it (Google does not hold it, a poll never changes it, and the API writes it on its own), so whose clock a floating event's instants are on is read from the event's flag, for `over` as for the body. T2: on this path (a change or delete of one event in no series on a Google calendar, the setting on), a request that carries `over` and changes the time sends it whole (`start`, `end`, `allDay` and `tzid`; a typed clock goes with all four), and an `over` that names the time names it whole. A time that is not whole in either place is refused before anything is read from Google or written: 400 `client_outdated`, "Better-Cal was updated. Reload the page and make the change again." Only a page that sends `over` with a time in parts sends such a request, and no released version does: a tab left open from 1.5.6 sends no `over` and is read as T3 says. T3: a request with no `over` (an API caller, a plugin, Review) is read against the row as 1.5.6 reads it (a start alone keeps the stored end, an end alone the stored start, a drag the stored kind), except that an all-day setting sent alone converts both boundaries; no new promise. T4 (narrowed 2026-11-15, after the third outside read): whole means the four parts are present, of the types the API reads (a boolean `allDay`, a zone it knows, boundaries it can read); that is all the entry check promises. Their values are read as the API reads any time: an all-day boundary's date is read and any clock on it ignored ("Time Zones and All-Day Events" in the API contract), and a date is read as the parser reads it everywhere (whether to refuse impossible dates is an API-wide question, filed separately). There is one check of the values, on the resolved time: the time as it stands after every reading between the request's entry and the write (the floating clock, a typed clock, the base from `over`, `columnPatch`'s reading and its change of kind), immediately before Google is read, has its end after its start, and the base read from `over` the same; a time that fails it is an invalid time, not an old client: 400 `invalid_request`, "end must be after start" (the words of `POST /events`), nothing read from Google and nothing written; only a timed time can fail it, since the API reads an all-day end on or before its start as one day long, the end the day after the start (#302). A timed end before the start meets the API's own reading first (`columnPatch`, 400 `invalid_request`, "end must not be before start"), the same code. `client_outdated` means a time in parts alone. The base the comparison uses for the time is `over`'s whole time, read in its own terms (its kind, its zone, its dates or instants). Nothing of one time is taken from the base and another part from the row, and nothing of the request's time is completed from anything. The time is served whole wherever the app can act on it: the full record, the grid's list shape (every list answer) and the question's values each carry `start`, `end`, `allDay` and `tzid`, an all-day time's zone UTC. One function says whether a time is whole (`Events::wholeTime`), at the entry, for the body and for `over`; one checks the resolved time (`Events::resolvedTime`, from `googleChoice`, which a change and a delete both pass through right before Google is read); one function gives the base (`Events::requestBase`), and the request is resolved over it (`columnPatch`, and `fromViewer` for whose clock a floating event's times are on); with no `over`, or off this path, the base is the row. The first build of this rule (2026-11-14, after an outside review found a stale drag read against the polled row) filled a part of the time the request left out from `over`; a second outside read found that base mixed `over`'s all-day dates with the polled row's zone and moved a date by a day. Both came from treating a time as parts, so the filling is gone rather than made more complete. Cost: a page that sends `over` with a time in parts is refused on its next time change or delete until reloaded (no released version does; a 1.5.6 tab sends no `over`); a start sent alone is no longer something a client with `over` can send.

**(c) The question.** HTTP 409, the error convention of [api-contract.md](../api-contract.md) (`{"error":{"code","message", ...extra}}`, `HttpError::$extra`):

```json
{"error": {
  "code": "google_changed",
  "message": "Since 'Team sync' was last shown to you, its title was changed in Google Calendar. It is now 'Standup' there; your change makes it 'Weekly sync'. It wasn't changed; open the event to see it and make the change again.",
  "fields": [{"field": "title", "was": "Team sync", "now": "Standup", "yours": "Weekly sync"}],
  "kept": [{"field": "location", "now": "Room 2"}]
}}
```

Values are in the served form; `time` is `{"start", "end", "allDay", "tzid"}`, the whole time; `yours` is null for a delete. To Overwrite, the client sends the same request again (same method and path) with, for each field in `fields`, `over[field]` set to its `now` (for `time`: `over.start`, `over.end`, `over.allDay` and `over.tzid`, all four) and the body's value for that field set to its `yours` (for `time`: `start`, `end`, `allDay` and `tzid`, all four). Overwrite sends what the question showed as yours, the time whole, as every request on this path does (b). The 409's `message` is the dialog's own sentences for the same fields (`GoogleConflict::question`, held to the app's `googleChangedAsk` by one table both suites read, `server/tests/fixtures/google-changed-words.json`), then what was not done and what to do; for a delete ("Since 'Team sync' was last shown to you, its location was changed in Google Calendar. It was 'Room 2'; it is now 'Sample Hall'. It wasn't deleted; open the event to see it, and delete it again if you still mean to."). That is how the server knows which Google state the person saw: if Google still holds it, base = now and the field is `send`; if not, it asks again. There is no `force`.

**(d) Kept on success.** The 200 answers of `PATCH /events/:id`, `POST /events/:id/relationship` and `POST /events/:id/rsvp` gain `kept: [{field, now}]`, the fields with `kept` outcome, values in the served form; empty when none. With the setting off the key is absent (after the independent read, 2026-11-14: the first build sent an empty `kept` with the setting off too); a PATCH answer for an event in no series carries `occurrence` either way (g).

**Words.** One name per field wherever a person reads it (the 409's own message, the dialog, the toast), the name the app's sentences already use: title, time, location, notes, link. The status field is never called "status" to a person: the sentence says Planned or Maybe. A delete either asks or keeps nothing.

**(e) The setting.** `google_conflict_choice` in `Settings::DEFAULTS`, `true`: on as 1.5.7 ships, by the owner's choice on 2026-11-14 (decisions.md). Not client-writable, not in the Settings page, read by `Settings::googleConflictChoice($db, $userId)` beside `googleSeriesUndo` through the defaults, so an account that stores nothing for it reads on and only an explicit `false` reads off. It stays as an operator's switch for one account and is to be removed in a later release; the edit harness's `--conflict-choice off` and the database test of the off path store `false`. Off: none of the step runs; every path is the 1.5.6 code, refusing with `google_conflict` and `GoogleWriter::CONFLICT_MESSAGE`.

**The step, one function every path in (f) calls.** Only on a Google calendar, only for an event row with no `rrule` and no `recurrence_parent_id`, only when the request sends Google something (a non-empty `patchBody`, a delete, an answer): `GoogleWriter::readNow` (GoogleWriter.php:722, the 1.5.6 first read, list by iCalUID, decided by `admit`); if the read differs from the row in the rule, 1.5.6's refusal; else the three maps, `threeWay`, the read stored through `Feeds::sync` with `journal: true` (as a poll stores it; today's `GoogleWriter::apply` passes `journal: false`, GoogleWriter.php:907) when it differs from the row; then either the 409 (the store kept through the deferred error, `googleDeferred` and `throwDeferred`, SeriesPlans.php:914 and :922) or the write as today, passing the read and its list to `GoogleWriter::patch` or `delete` (`$read`, `$items`, GoogleWriter.php:696, :706), whose condition then finds the rows equal to the read and reads nothing again.

**(f) Every single-event write path.**

| Path | Client entry | Server entry | Disposition |
| --- | --- | --- | --- |
| Edit (editor, popover, sheet) | `updateEvent`, web/src/app/actions.js:620 (EditorDrawer.js:877, EventPopover.js:464, EventSheetBody.js:383) | `EventsController` PATCH :148, `Events::patch` :1585, routed at :1893 to `googlePatchAll` :543 | Through the step |
| Drag | `moveEvent`, actions.js:413 | same | Through the step |
| Resize | `resizeEvent`, actions.js:436 | same | Through the step |
| Trip moves and extends | `moveTrip` actions.js:1142, `maybePromptExtendTrip` :402 | same | Through the step (same server path) |
| Delete | `deleteEvent` actions.js:709; `deleteTripOnly` :978; `deleteTripAndMembers` :995 | `EventsController` DELETE :164, `Events::deleteEvent` :2241, routed at :2307 to `googleDeleteAll` :775, single branch :807 to `googleRemove` :820 | Through the step |
| Planned or Maybe | `setRelationship` actions.js:805; the editor's `status` (EditorDrawer.js:842) | `EventsController::relationship` :223, `Events::setRelationship` :100, `relationshipChange` :126 gives a `status` patch, then `Events::patch` as an edit | Through the step. On an invitation it is attendance, held here alone, no Google write |
| Answer an invitation in no series | `rsvpEvent` actions.js:1626 | `EventsController::rsvp` :213, `Rsvp::answer` :225, `answerLocked` :241, `readNow` :408, `writeBesideDays` :428 (GoogleSeries.php:692, a single event goes to `GoogleWriter::patch`) | Read and store through the same comparison with nothing wanted, so no question; `kept` from it, base (the row) against now. The read is outside a transaction here, so the store is its own `Undo::write` before the send |
| Review applying an invitation's update or cancel | none | ReviewQueue.php:1070, :1099, :1167 | Through the step; the whole message on the item |
| Plugin runs | none | `Events::patch` | Through the step; the whole message |
| A day of a series, a whole series, "Use series value", undo | | `googlePatchThis`, `googleDeleteThis`, `googleDeleteOverride`, `SeriesPlans::useSeriesValues`, `GoogleUndo` | Not in slice 1: 1.5.6 behaviour |

**(g) What the person sees after an answer.** Added 2026-11-14, after the question was walked through against real Google. An open editor's fields are the person's from the moment it opens until it closes: nothing that refreshes the event underneath (a poll, the read-back at a question, a split followed) seeds its form again (`sameOpening` in `web/src/lib/editfields.js`; only where Back returns and what the person has been shown may change under it). An opening of the editor has one base: for each field, what the person has most recently been shown. It opens as the event as opened; a question shows Google's value for each asked field, so those fields' base becomes that value and every other field keeps its own (`baseOf` in `EditorDrawer.js`, from `editorBase` and `shownAfter`). A Save sends exactly the fields whose form value differs from the base, with `over` from the same base (`saveOf`), and the editor's unsaved mark and its discard prompt compare with the same base: a Save after Cancel says "I have seen it" and goes through, unless Google changed again, which asks again with the newer value, by (c); a field typed back to what it opened as is a change from what was last shown and is sent; a form holding only what the question showed sends nothing and closes. A poll that lands while the editor is open, with no question, moves no base: the editor did not show it. Questions are asked one at a time, in the order the server asked them (`confirmTurn` in `ConfirmDialog.js`): one that came while another was open sends its request again when its turn comes and asks from that answer, so its words are never older than the moment it shows, and every question is answered exactly once. The 200 of `PATCH /events/:id` for an event in no series carries `occurrence`, the event as saved (what `GET /events/:id/occurrence` serves); the app puts it in place of every copy held as a change that landed before the caller goes on, so the editor closes, the grid shows the result and the toast says it at the same moment, and the refetch of the window only confirms. A series' answer carries none: there the editor, the grid and the toast wait for the refetch together. Checked by `web/tests/google-changed.mjs` (the editor through a question, Cancel and a second Save; Overwrite and a drag's Overwrite with the window refetch held), `web/tests/google-conflict-callers.mjs` (every field of the editor through every path, from the base rule; two questions at once) and the scenario "Overwrite after the question".

---

# A Change Over Someone Else's Change at Google

Status of this revision (2026-11-13, third version, citations re-pointed at 1.5.6 on 2026-11-14 for sections 1, 2, 3, 5 and 7): every section is revised after the second independent read (`workfolder/reviews/google-156/read-models-2-report.md`) and the owner's rulings on it. Not done: a reader's pass over this version, and the live probes in section 8. The page above, for slice 1, outranks this model where they differ. Sections 4, 6, 8 and 9 still cite the pre-1.5.6 tree.

A written model, not built. It replaces the refusal "This event changed at Google since you last saw it. Refresh and try again." (`GoogleWriter::CONFLICT_MESSAGE`) for a change to one event or one day of a repeating event on a Google calendar, and gives the undo of a change to plain fields of all events (the second undo model's kind 1) the same comparison. The owner's rule ([google-calendar.md](../google-calendar.md), "When Someone Else Changes the Same Event"): a change at Google to a field we are not changing is no conflict; only the same field is, and then the person is shown it and chooses Cancel or Overwrite. His standing rule from this work: news the person has no reason to expect goes in a question at the moment of the action; a toast only confirms what they already expect.

Code in sections 1, 2, 3, 5 and 7 is cited on `main` at 1.5.6 (`3702b514`); sections 4, 6, 8 and 9 still cite the fix branch (`worktree-agent-a005dbfba679dfc71`) at `222f8b3d` to `9cdd9c5a`. Section 9 lists what this model leans on there and on the access model.

## 1. Where a Single Write Meets "Changed Since" Today

Every single-event or single-day write goes through `GoogleWriter::patch` or `GoogleWriter::delete` (GoogleWriter.php:696, :706). Their If-Match comes from `GoogleSeries::condition` (GoogleSeries.php:630). Since 1.5.6 its first request is the read every change makes first: `firstRead` (:741), a list by the iCalendar UID with deleted records shown, then `GoogleWriter::admit` (GoogleWriter.php:264, called at GoogleSeries.php:645), which refuses below writer before anything is read from the answer. The read is then compared with the rows here by `sameAsStored` (:835): a digest of `state()` (:1700) over `fieldsOf` (:1649, which since 1.5.6 also holds the zone), a day read as `exceptionOf` builds it (:190). Any difference throws `google_conflict` (:655). A 412 on the write throws the same (GoogleWriter.php:1036). A read that finds the event gone or cancelled throws `google_deleted` (GoogleSeries.php:652). A caller that builds its write from the read uses `GoogleWriter::readNow` (GoogleWriter.php:722, GoogleSeries.php:779), which makes the same first read and `admit`, and hands the read and its list to `patch` or `delete`, whose condition then reads nothing again (GoogleSeries.php:634).

| Write | Entry | Request | Compared | Client shows |
| --- | --- | --- | --- | --- |
| Edit or drag of a single event | `Events::googlePatchAll` (Events.php:543) | `patch`, :558 | The event's `fieldsOf` and rule | "Update failed: ..." (actions.js:646), "Move failed" (:431), "Resize failed" (:454) |
| Edit or drag of a day | `googlePatchThis` (:592) | `patch` | The whole series | same |
| "Use series value" on a day | `SeriesPlans::useSeriesValues` | `patch` | The whole series | "Update failed" (actions.js:677) |
| Planned or Maybe | `Events::setRelationship` (Events.php:100), from EventsController.php:232 | `patch` of `status` | The event or series | the relationship toast |
| Delete a single event | `googleDeleteAll` (Events.php:775), single branch :807 | `googleRemove` (:820), `delete` at :823 | The event | "Delete failed" (actions.js:726) |
| Delete one day | `googleDeleteThis` (:833), `googleDeleteOverride` (:858) | `googleRemove` | The whole series | same |
| Answer an invitation | `Rsvp::answerLocked` (Rsvp.php:241), `readNow` at :408 | `GoogleSeries::writeBesideDays` (:428; GoogleSeries.php:688), which for a single event is `patch` with the read | The event or series | the answer's toast (actions.js:1626) |
| Undo of a single edit, delete, create, answer | `GoogleUndo::undo`, `readNow` at GoogleUndo.php:509, :562, :643 | through `refusingConflict` | The event or series | "Newer changes exist for this item; ..." (GoogleUndo.php:1140) |

The comparison is content, but of the whole state: a guest renaming another Tuesday refuses a drag of this Tuesday, and a title changed at Google refuses an answer.

## 2. One Function

The same rule (a base, what Google holds now, what is wanted: ask only where now differs from both) was being designed four times: here, kind 1 of the undo, kind 2, and Finish and Put back. It is one pure function.

**The field map.** One normalised map of the fields Google holds and a row stores, built from `fieldsOf` (GoogleSeries.php:1649), the normaliser `sameAsStored` digests. One equality per field, each "what the person would see". (Slice 1, the page above: the notes compare by `fieldsOf` alone, `Sanitize::description` of each side, with no reading as text.)

| Field | Equal when |
| --- | --- |
| `title` | the trimmed text is the same, "(No title)" equal to empty (as `fieldsOf`) |
| `description` | the text the person reads is the same: `Sanitize::toText` (Sanitize.php:147) of `Sanitize::description` (:106) of each side, runs of white space made one space, trimmed; absent equal to empty |
| `location` | the trimmed text is the same; coordinates are held here, filled in the background by `GeocodeSweep`, and never in the map |
| `url` | `Ics::webUrl` of each is the same; absent equal to empty |
| `status` | both tentative or both not |
| `time` | one value: the same start and end instants and the same all-day setting. The zone is not compared: a zone-only change moves no instant of one event or one day, and the person sees the same time |

**Formatting-only changes to notes are no change.** (Not in slice 1: there a formatting-only difference may ask once; see the page above.) The product's `condition` compares sanitized markup, so a formatting-only change at Google still differs there; that difference is stored like any (section 3, the store) but makes no question and no "kept" line. A person's change that is formatting-only is still sent (whether a field is wanted is read from the request body, section 3); against a formatting-only change at Google it goes through, the person's formatting winning. The code does not make this wrong: the store keeps `condition` and the rows in step, and `threeWay` decides only what the person is told.

The rule of a series is not in the map: which days a change reaches is a precondition each caller checks (sections 3 and 4). Held here alone and not in the map: reminders, Planned and Maybe (`attendance`), tags, people, icon (`OwnFields::HELD_HERE`). Kept by Google and not in the map: colour, guests, conference links.

**The function.** `GoogleSeries::threeWay(array $base, array $now, array $wanted): array`, pure. (On 1.5.6 that name is a private helper of the series outcome words, GoogleSeries.php:2582; slice 1 builds it as `GoogleConflict::threeWay` in a class of its own.) `$base` and `$now` are full maps; `$wanted` is a partial map, only the fields the change sets, each as a whole value. It returns one entry per field:

| Outcome | When | The caller |
| --- | --- | --- |
| `same` | not wanted, now equals base | does nothing |
| `kept` | not wanted, now differs from base | sends nothing for it; Google's value stays and is shown where it lives (section 5) |
| `send` | wanted, and now equals base or equals wanted | sends it |
| `ask` | wanted, now differs from base and from wanted | sends nothing at all; lists `{field, was: base, now, yours: wanted}` |

Values are compared one field at a time; nothing is hashed. A bare `force` never passes an `ask`.

**The read and store.** Every caller uses the read 1.5.6 makes the first request of every change ([google-access-detect-and-inform.md](google-access-detect-and-inform.md)): a list by the event's or series' iCalUID with deleted records shown (`GoogleSeries::firstRead`, :741), its level decided by `GoogleWriter::admit` (GoogleWriter.php:264) before anything is read from it. A caller gets it, with its list, from `GoogleWriter::readNow` (GoogleWriter.php:722; GoogleSeries.php:779), and passes both to `patch` or `delete` (GoogleWriter.php:696, :706), so the write's condition reads nothing again. Only a read Google answered with `writer` or `owner` gets this far: `admit` refuses anything less with the access sentence, so a busy-only read is never compared or stored, and nothing in this step checks the level again. When the read differs from the rows, it is stored through the poll's path (`Feeds::sync`, Feeds.php:1255) with `journal: true`, an Activity entry "From Google calendar ..." with its touches, as a poll's is (today both write paths pass `journal: false`, GoogleWriter.php:907 and GoogleSeries.php:3145; without the journal an older entry's undo could overwrite what was stored, because `Undo::staleFor`, Undo.php:1355, would find no later touch). Once stored, the rows equal the read, so `condition`'s `sameAsStored` passes and the If-Match is the read's etag. One public function used by every caller; `GoogleSeries::refresh` (:4430) is private and takes a pending row.

## 3. A Change to One Event or One Day

**When Google is read.** Only when the change sends Google something: `patchBody` (Events.php:434) is not empty, or it is a delete. A tag, reminder, people or pin change stays a local write with no request (Events.php:555 to 562).

**The base.** The row, and, for each field the request changes, the value the server last served for that field, echoed back by the client untouched, in `over`: the comparison is against what the person was looking at, not a copy polls keep moving. Never the editor's form values: the rich-text editor writes "Agenda" and "Bring slides" on two lines as `Agenda<br>Bring slides` (web/src/lib/richtext.js) and an ampersand as an entity, which would ask weekly about notes nobody changed. The editor already sends only the fields the person changed (EditorDrawer.js:818 to 827, #199), so `over` names those. `over` has one form, the API's: the record the server serves (`serializeFields`, Events.php:2696; the notes at :2737; a window occurrence carries only `hasDescription`, :2799, so the notes' value comes from the full record the editor loads); the 409 gives `now` in the same form; a field the client has no served value for falls back to the row. A server test pins that serve-then-parse is the identity for every field: each field the API serves, sent back through the request's parsing (`typedTimes`, SeriesPlans.php:364; `columnPatch`, Events.php:2909), gives the row's map value.

For a day the row is read as the series state reads it: `exceptionOf` (GoogleSeries.php:190) for an edited day (a field it doesn't own taken from the series), `plainAt` (:2226) of the stored master for an ordinary day, the link with the tolerance of `UNWRITTEN`.

**Wanted.** Resolved once, before the store, into whole map values over the row as it was: a drag sends only a start and an end, and resolves to the time `{start, end, all_day}` of the row it was dragged on. Those values are what the person is shown as "yours" and what is sent. A field the request sets at its base value is not wanted, and is left out of what is sent (after the store, sending it would put back the value Google replaced). If the event became all-day at Google, the question says "2:00 PM" and Overwrite sends 2:00 PM timed, in the form Google holds (`GoogleWriter::overForm`, GoogleWriter.php:617); it never re-reads the drag over the stored all-day row. A delete wants every field at "deleted".

**Now.** The event in the read (`GoogleSeries::held`, :1482, of `readNow`'s list); for a day, its record when Google holds one, else the series' values at that day (`plainAt`).

**Preconditions,** checked on the read before `threeWay`; each is a notice with its own sentence (section 5). On 1.5.6 the first two are already answered by the code, and slice 1 keeps those answers:

- The event is no longer in the calendar's list at Google (deleted there, or moved to another calendar): an edit is refused. `condition` and `readNow` already throw `google_deleted` for a target the read lists cancelled or does not hold (GoogleSeries.php:652, :791), and a delete of it completes (`googleRemove`, Events.php:820). Refusing is a choice: a cancelled event can be made live by a PATCH (`insertChosen`), but reviving someone's delete to save a title is not what a person expects.
- A single event that is now repeating at Google: the change would reach every day of it. The rule is outside the field map, so on 1.5.6 the digest differs and `google_conflict` is thrown; slice 1 keeps that refusal and its sentence.
- A day whose key is not a day of the series as stored after the read (its time, zone or rule changed at Google, or the day was skipped there). Decided from the stored series, not from the instance GET, which then answers 404 or a cancelled stub.

**Outcomes.**

- No `ask`, no refusal: the store commits with the change, the write runs as today from the top over the stored rows, and the answer carries `kept` with each field's value now.
- Any `ask` or refusal: nothing is sent and nothing else of the request is written (tags, people, reminders and the trip flag in the same request wait for the Overwrite, which sends the whole request again). The store must still commit, so the calendar shows what Google holds: `Events::patch` and `deleteEvent` run inside `Undo::write`, where a throw rolls the store back, so it uses the deferred error the series path uses (`googleDeferred`, SeriesPlans.php:914, already set by `googleDeleteAll` for a series at Events.php:796; `throwDeferred`, SeriesPlans.php:922, called at Events.php:1696 for `patch` and :2258 for `deleteEvent`). The answer is 409 `google_changed` with `fields` (the `ask` entries, values in the API's form) and `kept`, or the refusal's code.
- **Overwrite** sends the whole request again with, for each asked field, the `yours` value from the 409 as the field's value and the `now` value as `over`. The server reads, stores and compares again: if Google still holds `now`, the field is `send`; if it holds yet another value, the question comes back with that value.

**The undo entry.** Unchanged in form, and right because the store comes first: before holds Google's version, after holds the person's change, `changed` the person's fields (`editChanged`, GoogleUndo.php:185). Undoing a drag moves it back and leaves a stranger's rename; undoing an Overwrite puts back the value the person overwrote. The store is its own entry, as a poll's is, so the stale check sees it.

**The invitation answer is never asked about.** The click is the choice and the person has the context. It uses the same read and store, builds the guest list from the read with only the account's own answer changed (Rsvp.php:408 to :428 already does this, through `readNow` and `writeBesideDays`), and sends it. On 1.5.6 that read is made outside a transaction and the answer is stored in an `Undo::write` after the send (Rsvp.php:432), so the store of the read is a write of its own before the send. It no longer refuses for a change to any other field; if the answer itself was changed in Google Calendar, the click wins. `threeWay` is not called: its body is the whole `attendees` list, not a field of the map.

## 4. Kind 1 Undo on the Same Function

The undo model's kind 1 (`docs/design/series-undo-on-google-v2.md` on `google-156-undo`), with both readers' changes applied. Kind 2, and Finish and Put back, are left out; they should later use `threeWay` (kind 2's list is its `ask` entries over the series and each day; Finish and Put back take the stop's read as base).

**Which entries.** A pure function over every table of the entry's two snapshots: kind 1 when the same event rows are in both, of one series, every differing column is a plain field (`title`, `description`, `location` text, `url`, `status`), and no row's own list differs. Bookkeeping columns (`google_etag`, `updated_at`) and coordinates are ignored. Tag and people links of the master that differ are sent back through the same forward call (`tagNames`, `personIds`); a link change on a day makes it kind 2. Everything else is kind 2 and stays held back by the setting's sentence, gated by kind in code: switching `google_series_undo` on reaches kind 1 only, never the existing `undoSeries` for kind 2.

**Inputs.** `$base`: the master in the entry's after snapshot. `$wanted`: the master in the before snapshot, for the fields the entry changed. `$now`: the master as stored after `readAndStore` of the series (a change to all events reads and refuses today; it never stored).

**The days it reaches.** The series' rule and bound (its UNTIL or COUNT) now are compared with the after snapshot's; skips are not (a day deleted since is not a reason, and the old sentence would be untrue). Equal: the undo reaches the days the change did. Different (renamed for all events, then split by "this and following", or its repeat changed): the undo goes ahead on the days the series has now, and since that is news the person has no reason to expect, it is part of the question (section 5) whether or not a field is asked.

**Outcomes.** No `ask` and the same days: `Events::patch` with `scope: all`, the `send` fields at their before values, under `withPreselectedPlan` (SeriesPlans.php:42), so a day that owns the field keeps it (`own<Field>`, default keep, Series/Model.php:2565) and no `series_plan_required` is thrown; the forward path sends the master, then each day not as the plan says, stores the read-back and journals with `undoes`. Otherwise nothing is sent and the Activity page asks; Overwrite sends `over`.

**One question, not two.** `Undo::undoById` runs `staleFor` before the Google undo (Undo.php:2328, :2335). For the plain fields `threeWay` replaces it; `staleFor` stays for what it doesn't compare (tags, people, local columns). `undoRun` (Undo.php:2356) forces when not strict and lists only `undo_conflict` as refused: a run's undo that would ask is listed as refused with the question's first sentence, never overwritten and never counted as skipped.

**A stop part way.** Recovery branches on `plan['undo']` and puts the entry's snapshots back (SeriesPlans.php:1307 to 1337). For kind 1 the local part is the forward plan writer's, journaled as the undo; the plan records the kind.

**Not switched on until** the forward path sends back the notes Google clears on a master write that leaves them out (replay round 4, cause 2; probe 3.52 to 3.57).

**Beyond the shared function,** kind 1 needs: the kind, the days check, `staleFor`'s replacement, `over` on the undo endpoint, `undoRun`'s listing, the recovery branch, the gate. The forward change needs: `over` and its served form, `wanted` resolved whole, the preconditions, the deferred 409, `kept`, the client's question.

## 5. What the Person Is Told, and Where

The server returns data only: per asked field `{field, was, now, yours, from}` (`from` is `day` or `series` for a day), per kept field `{field, now}`, and for a notice its code with the event's title and the day. One client module, beside `lib/planwords.js`, composes every sentence, every date and time through `lib/dates.js` in the person's locale and 12 or 24 hour setting. Values: text in quotes; an empty value as "no location", "no notes", "no link"; notes as the text the person reads, shortened to about 80 characters from a little before where the two differ, with "…" where cut.

**Where each piece of news goes,** by the owner's standing rule:

| News | Bears on the action? | Where the person learns it |
| --- | --- | --- |
| The same field was changed at Google | Yes: the action would replace it | A question, before anything is saved |
| The event was changed at Google, and the action deletes it | Yes: the delete takes that change with it | A question (the owner's ruling) |
| A kept-at-Google change for an all-events undo whose series no longer covers the same days | Yes | A question |
| The event is gone from the calendar at Google; a day is no longer a day; a single event is now repeating | Yes: the action can't be made | A notice with OK, at the moment of the action |
| Google refused in the write's own seconds (412) | Yes | A notice with OK |
| Another field was changed at Google and kept | No: the action is untouched by it | Shown where it lives: the event, as the person sees it right after, holds Google's value, and the toast confirming the change names it. **For the owner:** this is news the person had no reason to expect, in a toast; by the letter of the standing rule it would be a question every time anything changed at Google, which brings back the caution he asked to remove. This model keeps the toast and asks him to confirm |
| The store's own Activity entry | No | Activity, as a poll's |

**The question's dialog** is `askConfirm` (ConfirmDialog.js:14) with a body of several lines. **Cancel** closes it and returns the person to the editor with their text intact; the window refreshes, so the event (and a dragged block) shows where and how Google has it. The sentences, for one event 'Team sync':

- **The question and the toast, as built.** One copy of the sentences: `googleChangedAsk` and `keptWords` in `web/src/lib/planwords.js` compose them, the server's 409 `message` is the same sentences (`GoogleConflict::question`) then what was not done, and `server/tests/fixtures/google-changed-words.json` holds every field, for a change and a delete, with the body and lines both sides must give; `web/tests/google-changed.mjs` reads the dialog's titles, foot and buttons and the toast's line as prose. In short: one field is said in sentences ("Since 'Team sync' was last shown to you, its title was changed in Google Calendar. It is now 'Standup' there; your change makes it 'Weekly sync'."), several get a line each; each field has one noun (title, notes, location, link, time) and Planned or Maybe is said by its value; two values are never joined by "to". Planned or Maybe is asked about only on a delete: with two values, a change that differs from what was shown is what Google holds now or what it was.
- **An event gone at Google.** A notice, OK: "'Team sync' is no longer on 'Team' in Google Calendar: it was deleted there or moved to another calendar. Your change wasn't saved, and the event is gone from 'Team' here too. If it was deleted by mistake, it can be restored from the trash in Google Calendar."
- **A day that is no longer a day.** A notice, OK: "Tue, Nov 18, 10:00 AM is no longer one of the days of 'Team sync': the repeating event was changed in Google Calendar. Your change wasn't saved. The calendar shows 'Team sync' as Google Calendar has it now; open the day you meant and change it again."
- **A single event made repeating.** A notice, OK: "'Team sync' was made a repeating event in Google Calendar. Your change wasn't saved: it would now change every day of it. The calendar shows it repeating now; open the day you meant and change it again."
- **A day of a repeating event, asked.** The day's own record: "Since Tue, Nov 18 of 'Team sync' was last shown to you, its time was changed in Google Calendar. It is now 10:00 AM there; your change makes it 2:00 PM." "Overwrite changes this day only, to 2:00 PM. Cancel saves nothing." From the series: "Since 'Team sync' was last shown to you, its title was changed in Google Calendar, for every day. It is now 'Standup' there; your change gives Tue, Nov 18 the title 'Weekly sync'." "Overwrite gives this day 'Weekly sync'; the other days keep 'Standup'. Cancel saves nothing."
- **Google's 412.** A notice, OK: "Google Calendar didn't take this change: 'Team sync' changed there a moment before it arrived. Nothing was saved. Try it again."
- **Kind 1 undo, asked.** "After this change, the title was changed again: it's 'Team sync' now. Undo puts back 'Standup', replacing 'Team sync'." "Undo puts back 'Standup'. Cancel leaves 'Team sync' and doesn't undo." With the days changed: "'Standup' ran until Apr 30 when this change was made; it now ends Mar 7 (the days after are a separate event, or the repeat changed). Undo puts back the title on its days through Mar 7 only."

**Entries with no dialog** get 409 `google_changed` with the same data and a message that is whole on its own: "Since 'Team sync' was last shown to you, its title was changed in Google Calendar. It is now 'Standup' there; your change makes it 'Weekly sync'. It wasn't changed; open the event to see it and make the change again." It is the dialog's sentences, then what was not done (several fields: a line each, as the dialog has them; a delete: "... It wasn't deleted; open the event to see it, and delete it again if you still mean to."). The inventory:

| Entry | Dialog? | What it gets |
| --- | --- | --- |
| The app's editor, drags and resizes, delete, Planned or Maybe, "Use series value" | Yes, through `api.js` | The question |
| The app's loops (deleting a trip and its events, `deleteTripAndMembers`, actions.js:995 to :1007) | Yes, through `api.js`, per event | The question for the event that asks; Cancel stops the loop, and the events already deleted say so in the toast |
| The Review queue applying an invitation's update or cancel (ReviewQueue.php:1070, :1099, :1159, :1167) | No | The whole message on the Review item; the item stays open |
| The API (agent tokens) | No | The whole message and `fields`; a client may resend with `over` as documented |
| A plugin's run through `Events::patch` (Events.php:1585) and `undoRun` | No | The whole message; a run never overwrites |
| CalDAV | Not an entry | A Google calendar is `subscribed` here (Calendars.php:229, GoogleMove.php:311), which CalDAV refuses to write (Dav/CalendarBackend.php:601, :622 to :624) |

## 6. Siblings

| Site | Disposition |
| --- | --- |
| `Events::googlePatchAll`, single event (Events.php:500) | Follows, slice 1 |
| `Events::googleDeleteAll`, single event (:765) | Follows, slice 1 |
| `Events::setRelationship` on a single event | Follows, slice 1 |
| `Rsvp::answerLocked` (Rsvp.php:423) | Read and store, no question; slice 1 for an event, slice 2 for a series |
| `Events::googlePatchThis`, both branches (:577, :602) | Follows, slice 2 |
| `googleDeleteThis`, `googleDeleteOverride` (:775, :800) | Follows, slice 2 |
| `SeriesPlans::useSeriesValues` (SeriesPlans.php:1573) | Follows, slice 2 |
| `GoogleUndo::undo`, EDIT (GoogleUndo.php:516) | To change, slice 3: base the entry's after body, wanted its before for `changed`, now the read |
| `GoogleUndo::undo`, DELETE, CREATE, ANSWER (:564, :591, :606, :634) | To file: the same function fits, each needs its sentence; until then the whole-state refusal |
| A change to all events, "this and following" (`condition` at GoogleSeries.php:819, :833, :853) | To file: through the plan step (store, then `series_plan_changed` with the new plan, api-contract.md:185), after the run redesign |
| Kind 2 undo, Finish, Put back | To file: later on `threeWay` |
| `GoogleMove` (GoogleMove.php:283) | Stays: writes instances it has just imported; no row here describes them |
| `GoogleSeries::insertChosen` (:1112) | Stays: makes live an id the app chose and just read |

## 7. The Check

**The sentence.** For a write to one Google event or one day, with the test's own records of B (the row, with the API's served value for each changed field the request echoes), G (what the stand-in held just before) and W (the request's fields resolved whole over the row), field by field by the equalities of section 2: the server asks exactly when some field has G ≠ B, W ≠ B and G ≠ W, and lists exactly those with B, G and W; when it asks or refuses, Google is sent nothing, the rows hold G, and nothing else of the request is written; when it doesn't, Google and the rows hold W for every field the request changes and G for every other, and `kept` names exactly the fields with G ≠ B that W doesn't change; an Overwrite goes through when the stand-in still holds the G it showed, and asks again, with the new value, when it doesn't. A delete is W = every field. For kind 1, B is the harness's record after the entry's step, W its record before it, G its record just before the undo, over the series' master.

The test's equalities are its own code, written from the table in section 2; nothing in `server/src` is called for the expectation.

**The tests (slice 1, replacing the generator this section first proposed).** No generator: `threeWay` is pure over six fields, so its check is an exhaustive table (`server/tests/google-conflict-threeway.php, included by server/tests/run.php`): for each field, every combination of G = B or G ≠ B with the field absent from the request, sent at B, sent equal to G, or sent at a third value, and a delete; plus every pair of fields, so one field asking and another kept are listed together. The request-level test is one database test file (`server/tests/db/google-conflict-choice.test.php`) of hand-listed scenarios, the ones the page at the top names, sending client-shaped requests: the API's own answer for the event is read first and `over` is built from it, as the app does, and the stand-in for Google is changed between. A separate test pins serve-then-parse as the identity for every field. A busy-only read needs no case of its own: `admit` refuses it before the step (GoogleSeries.php:645), as `google-access.test.php` already shows. Spellings of unchanged notes are left to live probe P2.

**Damage it must catch,** each injected as a mutation: a field written over that was not shown; a change at Google lost; a question when nothing collided; no question when something did; a question or a kept line when nothing changed (another normaliser, or form values as base); a store rolled back by the 409; a store not journaled; tags written on a question; a day that reads its series' new title as its own; a time asked about by its end alone; Overwrite re-resolving a drag over the stored row; a field sent at its base value put back over Google's after the store. (The day's mutation and the busy-only read are not slice 1's.)

## 8. Slices

Counts are estimates with the check counted; the check is the larger half of each.

**Before any slice: the access rule** (`access-model.md`). Every write's first request becomes the list by iCalUID that carries `accessRole`, decided in `condition`. This model's read is that read; it assumes the level is decided before its step runs and adds nothing to it. The access rule is smaller and this depends on it, so it is built first.

**Slice 1: single events** (edit, drag, resize, delete, Planned or Maybe; the answer to an invitation in no series).

- Server, about 8 files: `GoogleSeries` (`threeWay`, the map and its equalities, `readAndStore`; new), `GoogleWriter` (`apply` journaled, the read passed through), `Events` (the step, new; `googlePatchAll`, `googleDeleteAll`, `googleRemove`, `setRelationship`, `patch` and `deleteEvent` for the deferred 409 and `kept`), `Rsvp`, `EventsController` and `columnPatch` (`over`), `Feeds::sync` (touches for a single resource), `HttpError` users for `google_changed`, `ReviewQueue` (the whole message on the item). About 5 new functions, 15 touched.
- Client, about 6 files: `api.js` (the interception), `ConfirmDialog.js` (a body of several lines), the sentences module and its prose test, `actions.js` (`over` from served values; the kept line; the cancel and loop handling), `EditorDrawer.js` (stays open on Cancel; `over` from the loaded record), the Activity entry's words.
- Check: the generator test file, the serve-then-parse test, the stand-in's busy-only answers (shared with the access model), the harness's single-event oracle and its `x-conflict` (tools/edit-harness/Interleave.php:1745 to 1790, which expects every write over an unpolled change to be refused, flips), its probes (OracleProbes.php:106, :110), the mutations list. Flips also: `google-etag.test.php` "a 412 is 409 google_conflict" (:202) keeps its refusal with new words.
- Before switching on: the access rule built; every mutation caught; a reader who did not build it; probes P2 (widened) and P3, and the new access probe.

**Slice 2: one day of a repeating event** (edit, drag, delete, "Use series value", Planned or Maybe on a day; the answer on a series).

- Server, about 5 files: `GoogleSeries` (the day's base and now through `exceptionOf`, `plainAt`, `held`, `UNWRITTEN`; the "still a day" precondition; `store` journaled), `Events` (`googlePatchThis` both branches, `googleDeleteThis`, `googleDeleteOverride`), `SeriesPlans::useSeriesValues`, `Rsvp`. About 3 new, 8 touched. Client: the day sentences.
- Check: the generator's day cases, the harness's day oracle; flips `google-etag.test.php` "a series changed at Google since the last poll refuses a write to an ordinary day" (:189) and `x-conflict` for days.
- Before switching on: P7, P8.

**Slice 3: kind 1 undo, and the undo of a single edit.**

- Server, about 6 files: `GoogleUndo` (the kind, the kind 1 undo, EDIT on `threeWay`), `Undo` (`undoById`, `undoRun`, the gate), `SeriesPlans` (preselected plan, recovery by kind, `undoes`), `Events` (the forward call with tags and people), `GoogleSeries` (the days check), the Activity controller (`over`). About 4 new, 11 touched. Client, about 3 files: the Activity page's question, the toast's undo, the module's undo sentences.
- Check: the harness's undo oracle (tools/edit-harness/Sequences.php:276 on), kind 1 generator cases; flips `google-undo.test.php` :224 and its words at :243, the redo refusal at :321, `google-series-undo.test.php` :361 and :381, `x-conflict` for undo.
- Before switching on: the forward path's notes fix; P9 to P11.

**Live probes still needed** (the second reader found P1, P4, P5, P6, P9 and P10 answered by evidence already):

- P2, widened. Notes written in Google Calendar's web editor with bold, a link, a line break, a non-breaking space and an ampersand, and plain-text notes with a line break and an ampersand: poll, read by the list, and check the map's equality holds between the read and the row; then serve the event through the API, echo the served notes back as `over`, and check equality again.
- P3. A single event made repeating in Google Calendar's web editor: its id is unchanged and the list by its iCalUID shows `recurrence`.
- Access. The step's read at view-only and busy-only, through the request it will use: does a list by iCalUID carry `accessRole`, and what does a busy-only list hold for a single event.
- P7. A series whose link (`source`) is changed by another client: an edited day's record holds the old link or the new.
- P8. A series whose rule is changed by another client so that a day held here is no longer in it: the stored list read no longer has the day.
- P11. A master write whose body gives a field the value Google already holds: do the records' etags move?

## 9. What This Leans On

- **The access model**: the first read is a list by iCalUID decided in `condition`; this step runs only after it said writer or owner.
- **The fix branch**, still changing (`dayCondition` going, day writes in a run without If-Match, three refusals and a prediction fix): `condition`, `sameAsStored`, `fieldsOf`, `exceptionOf`, `plainAt`, `held`, `UNWRITTEN` (slices 1 and 2); `store` and `readBack` (slice 2, slice 3); `run()`, the recovery branch at SeriesPlans.php:1307 and `GoogleUndo::entryToFinish` (slice 3 only). Nothing here uses `dayCondition`, `afterMaster` or `rebase`.

Order: the access rule, then slice 1, slice 2, slice 3. Slices 1 and 2 do not wait for the run redesign; slice 3 waits for its notes fix.

## Where Merge Would Attach

A third button beside Overwrite, for the description only, where both texts can be kept.

## Where This Differs From the Second Reader

- **CalDAV is not an entry.** The reader lists it among the entries with no dialog; a Google calendar is `subscribed` here (Calendars.php:229, GoogleMove.php:311) and CalDAV refuses writes to it (Dav/CalendarBackend.php:601 to :602, :622 to :624).
- **The kept line.** The owner's standing rule, read literally, makes it a question; this model keeps it in the toast because the change it reports bears on nothing the person is doing, and asks him to confirm (section 5).
