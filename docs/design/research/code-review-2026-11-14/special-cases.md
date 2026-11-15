# Families of special cases: where one rule could replace several (2026-11-14)

Read-only review of `main` at 3702b514. Nothing in the repository was changed, no test or gate was run. Every finding below comes from reading the members side by side; where a claim is a candidate rather than a confirmed fault, it says so.

## How the search was done

- Background read first: AGENTS.md ("Fixes follow from structure"), ROADMAP.md, and every entry title of docs/design/decisions.md, with entries 81, 116, 122, 134, 137, 145, 158, 162, 165, 177, 185, 188, 190, 221, 231 and 233 read in full (the ones that already declare "one function" rules, so the search could look for their unlisted siblings).
- Structure survey: line counts of every server and client file; counts of `match`/`switch`/`elseif` per server file (few: the code base mostly uses tables and early returns, so the families are in parallel functions and parallel tables, not in big switches).
- Targeted inventories, each by exact-string grep over `server/src` and `web/src` (ygrep's index exists; the counts below are from `/usr/bin/grep`, which is exhaustive): every function named `same*`, `*changed*`, `*Diff*`, `normaliz*`, `canonical*`, `compar*`; every hand-written list containing `'title'` with `'location'`/`'description'`/`'url'`; every write to the `events` table (`update('events'`, `UPDATE events`, `insert('events'`, `DELETE FROM events`) against every `ChangeLog::` call, per file; every `DELETE FROM events` against the retention functions; every `ChangeLog::record`; every Review kind, route, dismiss function and client branch; every view id table on the client; every scope (`this|following|all`) branch; every Activity sentence literal; every parsed-event-to-columns mapping (`columnsOf`, `eventColumns`, inline `$cols`); month and weekday name tables; client error-code branches.
- Then the candidate members were read in full.

## Ranked summary

Worth doing:

1. CalDAV change journal (rule: decision 233). One confirmed missing member, a family of unjournaled writers in Google recovery, and the rule is checked per writer only.
2. Parsed event to row columns: three mappings of one shape, disagreeing on floating, reminders, zone and skips.
3. Names of an event's fields: at least nine tables and message sites, already disagreeing in the same flows.
4. Review notices: per-kind dismiss routes and lists that decision 116 left behind.
5. Removing one day of a series: four functions, three different Activity lines for one act.

Worth doing when that code is next touched:

6. Which views exist (client): about twelve places name the set of views; 1.6 (#61, #99) is the next touch.
7. "Is this stored value the same": four readings that disagree on JSON text, NULL vs empty and coordinates.
8. Activity lines for event changes: picked by a heuristic on which row differs, hand-written on Google.
9. Scope dispatch and the write preamble of edit, delete and answer.
10. Quick add text parser: two rules repeated per phrasing, one copy disagreeing.

Covered by planned work (inventory for its author):

11. Google field map: about fifteen tables and loops naming what Google holds (#274).
12. Undo of a Google change by kind of entry (the plain-fields undo as a forward change; 1.6 deltas).

Leave alone: see the end.

---

## 1. CalDAV change journal (worth doing)

The rule (decisions 233): every change to an event writes its `dav_changes` row and bumps the calendar's synctoken in the change's own transaction, "whatever made it". It is one function (`ChangeLog::record`, `recordMany`, `recordUpdate`), called by hand after each writer. Unlike the link rule (188) and provider identity (190), it is checked per writer: `server/tests/db/dav-journal.test.php` covers the Events domain only.

Members (every file that writes `events`, writes vs journal calls, from grep):

| Writer | Event writes | Journals | Verdict |
|---|---|---|---|
| Events.php | 30 | 13 (`1473, 1964, 1966, 2011, 2133, 2144, 2196, 2237, 2328, 2350, 2375, 2486`, `255`) | follows |
| SeriesPlans.php | 14 | 6 (`665, 669, 725, 729, 1620, 1624`) | follows |
| Feeds.php (sync, every poll and Google store) | 10 | 2 (`1618, 1626`, one per changed uid) | follows |
| Rsvp.php | 4 | 2 (`293, 367`) | follows |
| Trips.php | 1 | 2 (`350, 352`) | follows |
| Undo.php (restore) | 4 | 2 (`2442, 2749`) | follows |
| Dav/CalendarBackend.php | 5 | 3 (`352, 511`) | follows |
| Plugin/PluginHost.php | 3 | 1 (`1072`) | follows |
| **CalendarImport.php:183** (import into an existing calendar) | 1 insert per event | 0 | **missing**: events imported into a calendar a CalDAV client already syncs are never journaled; the client learns of them only when some later change bumps the token |
| **GoogleSeries.php** rewind, placesBack, bringBack, relink, `3173, 3230, 3805, 3878, 3947, 4161, 4171, 4260, 4732, 4735` | 10 | 0 | **candidate**: Finish and Put back restore rows (reminders, which iCalendar output carries as VALARM; rows brought back; a row re-keyed by delete and upsert) outside the sync, whose own journal covers only the uids the sync itself changed. Every calendar, Google ones included, is served over CalDAV (`CalendarBackend::getCalendarsForUser`). Needs a check before calling it a bug |
| GoogleUndo.php:670 (attendance), ReviewQueue.php:1213 and MailIngest.php:1128 (invite_json), GoogleWriter.php:873 (etag), GoogleMove.php (google ids, source), Calendars.php:459 (source), Ranking.php:119 (score), GeocodeSweep (coordinates) | | 0 or `touchAccount` | not iCalendar content (`Ics::buildEvent` writes none of these), correctly not journaled |
| Plugins.php:702 (plugin calendar's events deleted) | 1 | 0 | likely fine (the calendar goes with them); confirm |

The rule that would replace the hand calls: compute the journal from the change rather than ask each writer. Two steps, the first alone is worth it:

- A check over all data, as 188 and 190 did for links and identity: after every `scenario()` block, every `events` row whose iCalendar-visible columns changed (the columns `Ics::buildEvent` reads: uid, times, zone, all_day, rrule, exdates, title, description, location, url, status, reminders, recurrence keys, deleted_at) has a `dav_changes` row for its calendar and uid written in that block. Raw SQL plus a before/after read, calling nothing in `server/src`. This finds every writer someone did not list, including ones added later.
- Optionally then: one hook at the end of `Undo::write` (the transaction 233 requires) that journals every (calendar, uid) the entry's snapshots and touches name, replacing about 30 call sites. Writers outside `Undo::write` (GoogleSeries' journaled steps) would still need theirs; the check covers them.

Superset of today: yes; it adds journal rows where they are missing and changes nothing else. Evidence: decision 188's own words ("every earlier fix of this rule was verified at the sites someone enumerated, and the next review found a writer nobody had listed") describe exactly this rule's current state. Size: the check about 60 lines in `server/tests/db/lib.php`, two fixes of a few lines each; the hook about minus 80 plus 60. Agent time: 2 to 4 hours plus a database suite run. Risk: low for the check; the hook touches every writer's transaction and needs `dav-journal.test.php` and the CalDAV tests. No stored form changes.

## 2. Parsed event to row columns (worth doing)

`Feeds::columnsOf` calls itself "the one mapping from the parsed shape to a row". Two siblings map the same `Ics::parse` shape on their own:

| | Feeds::columnsOf (Feeds.php:950) | DavIcs::eventColumns (Dav/DavIcs.php:89) | CalendarImport inline (CalendarImport.php:145) |
|---|---|---|---|
| zone | as parsed | `Time::normalizeTzid` | as parsed |
| empty rule | as parsed | `'' => null` | as parsed |
| skips | json of parsed | `Recurrence::validateExdates` first | json of parsed |
| status | as parsed | default `confirmed` | as parsed |
| floating | when the key exists | **not written** | written, with the calendar's preset |
| reminders (VALARM) | not written (feeds never carry them, by design) | **not written** | written |
| invite_json | when the key exists | not written | not written |

Real differences: a feed must not overwrite reminders (held here), and an import's calendar preset for floating is a real input. Accidents, as far as the code shows: CalDAV PUT drops `floating` (a phone's event with no zone is stored pinned to the home zone, and a client that changes a floating event to a zoned one leaves `floating = 1`), and CalDAV PUT drops VALARMs while export writes them, so an alarm changed in a phone's calendar app reverts silently. No decisions entry covers either; ask the owner whether they are intended.

Rule: `columnsOf` is the one mapping with the per-source remainder as data: which held-here columns a source may write (feed: none; CalDAV and import: reminders), and the normalizations every source gets (zone, empty rule, validated skips, status default). Superset, except it would start writing floating and reminders from CalDAV, which is a behaviour change to confirm. Size: minus 40 plus 15, 1 to 2 hours. Tests that pin it: the CalDAV tests (`caldav-kept-here.test.php`, `dav-query.php`), `floating.test.php`, import tests in `run.php`. No stored form changes.

## 3. Names of an event's fields (worth doing)

Decision 81 says the editor's lines come from one table (`EDIT_FIELDS`) and what a day keeps reads the server's one table (`Model::OWN_WORDS`). The same fields are also named in at least seven more places, and they disagree:

| Field | Model::OWN_WORDS (Series/Model.php:55) | planwords FIELD_NOUNS (web/src/lib/planwords.js:369) | actions OWN_FIELD_WORDS (web/src/app/actions.js:655) | EDIT_FIELDS label (web/src/lib/editfields.js:24) | GoogleSeries words (GoogleSeries.php:2433, GROUP_WORDS 2556) | ReviewQueue::inviteDiff (ReviewQueue.php:95-124) |
|---|---|---|---|---|---|---|
| location | place | **location** | place | Place | place | **Location** |
| description | notes | notes | **description** | Notes | notes | **Description** |
| attendance | answer | answer | **attendance** | (none) | | |
| allDay | all-day setting | all-day setting | **all-day** | (Time) | | All day |
| status | status | status | **planned or maybe** | For me | status | Status |
| reminders | reminders | reminders | reminders | **Reminder** | | |

More members: `GoogleSeries::DONE_WORDS` (2604, verbs per field), `Feeds::droppedDay` (Feeds.php:872, "its coordinates", "its answer"...), `GoogleSeries.php:2433` repeats `GROUP_WORDS` minus two keys inside `outcomeWords`, `Undo::TOGETHER` (Undo.php:1204) repeats `OwnFields::COLUMNS['location']` column for column, and the read-only refusal messages written per site: Events.php:1757 and 1771 ("attendance, tags, reminders and a place's coordinates"), Events.php:1781 and SeriesPlans.php:151 ("attendance, tags and reminders"), Plugins.php:1622 and 1626. All four permission messages are composed by hand from a rule that lives in `OwnFields::feedEditKeys` / `HELD_HERE` (reminders, attendance, tags, people, icon, coordinates): every message leaves out people and icon, and two leave out coordinates, though the check behind all of them is the same `googleKeys` call.

Already wrong (wording): in one series dialog the server's choice says "keeps its own place" while the client's aside says "(title, location and 2 others)"; "Use series value" toasts say "description" where the editor says "Notes"; the refusal sentences understate what a feed's or plugin's event accepts.

Rule: one field table on the server (extend `OwnFields`: per field its columns, request keys, held-here flag, noun, label, verb), served to the client with the config it already loads, and every sentence above reading it; refusal messages composed from `feedEditKeys`. Superset: same behaviour, wording unified (the owner picks the one noun per field). Size: about minus 70 plus 40 across both sides, 2 to 3 hours. Tests: the smoke "field table:" and PHP "Keeps:" tests (decision 81), planwords unit tests, google-series outcome tests that pin words. No stored form.

## 4. Review notices: routes, lists and toasts (worth doing)

Decision 116 made one dismissal (`ReviewQueue::dismissNotice`) and one list (`NOTICE_KINDS`). Around them the per-kind copies stayed:

- Four wrappers that only pass a kind list: `dismissMailLimit` (ReviewQueue.php:902), `dismissModelLimit` (908), `dismissMailError` (1366), `dismissGoogleDifferences` (1624, which takes four Google kinds but not `google_half`).
- Four routes and controller methods for them (entry-web.php:296-299; ReviewController.php:463-478 and 495) beside the generic `/review/notices/:id/dismiss` (entry-web.php:300) that `subscription` and `google_half` already use.
- ReviewController.php:75 hand-copies `NOTICE_KINDS` less two kinds, and :77-81 picks a dismiss path per kind with a `match`.
- ReviewPage.js:116-158: one toast branch per kind; a new notice kind falls to the final `else`, which says "Dismissed. Your calendar is unchanged" or "Change applied". The page's own header says it "does not need to know what a kind's endpoints are"; it does need to know each kind's words.
- Holders: `holdGoogleDifferences` (1379) and `holdGoogleAnswerLines` (1450) are the same insert with another summary; `holdMailLimit` (551) and `holdModelLimit` (611) are the same bounded counter row with other words.

A new notice kind today touches: the KIND constant, NOTICE_KINDS, controller list, controller match, a route if it wants its own, KIND_LABEL, KIND_ICON and a toast branch. Rule: every notice's Dismiss is `/review/notices/:id/dismiss`; the controller's notice branch is `in_array(kind, NOTICE_KINDS)`; each action carries its own `done` sentence from the server and the page toasts it; one holder for an event notice and one for a counted notice. Superset: yes; the four old routes can stay as aliases for one release or go (the client never builds them, it runs the action paths it is given). Size: minus 80 plus 25, 1 to 2 hours. Tests: `access-read-2-dismiss.test.php`, `access-read-review.test.php`, the review tests in `run.php`.

## 5. Removing one day of a series (worth doing)

| | deleteThis (Events.php:2331) | deleteOverrideInstance (2353) | googleDeleteThis (833) | googleDeleteOverride (858) |
|---|---|---|---|---|
| reached by | scope `this` from the series | the day's own row, any scope | scope `this` on Google | the day's own row on Google |
| finds the day's row | yes | given | yes, and uses its Google id | given |
| entry's entity | series | day row | series | day row |
| op | update | delete | update | update |
| Activity line | default: **"Updated event 'Series' (series' first date)"** | default: "Deleted event 'Day title' (its date)" | "Deleted one occurrence of 'Series'" | "Deleted one occurrence of 'Day title'" |
| ground | snapshots | snapshots | SKIP of the key | SKIP of the key |

The two local functions do the same writes (skip added, row deleted, links kept for undo); the two Google ones likewise, and `googleDeleteThis` already handles a day that has a row. The one act gets three different lines, and on a local calendar the common case (a day with no row of its own) is named "Updated" with the series' first date, not the day removed: `Undo::defaultSummary` names the after row that differs, which is the series. The SKIP ground literal `[[Undo::keyOf('events', ['id' => master]), Undo::SKIP . key]]` is written five times (Events.php:855, 873; GoogleUndo.php:602, 619, 633).

Rule: resolve (series, key, row or none) once, then one local and one Google remover, each journaling "Deleted one occurrence of 'title' (date)" composed from the series and the key. Superset; changes the local Activity wording (pinned by nothing found). Size: minus 50 plus 20, about 1 hour; the Google half is inside #274's reach and can wait for it. Tests: the delete scenarios in `edits.test.php`, `google-undo.test.php`, `google-poll-guards.test.php` (pins the Google line).

## 6. Which views exist (when next touched: 1.6, #61 and #99)

Members (web/src unless noted): `VIEWS` (app/actions.js:23), `rosterViews` narrow filter (actions.js:28), `VIEW_LABELS` and `VIEW_ICONS` (app/commanddefs.js:63, 74), `VIEW_OPTIONS` (app/SettingsPage.js:39, labels again), `VIEW_TYPE_LABELS` (app/SavedViewsPage.js:11, labels again), `STEP_UNITS` (app/Toolbar.js:42), `stepAnchor` arms (actions.js:120), the glide set in `navigate` (actions.js:114) and again in `jumpAnchorFor` (actions.js:166), `VIEW_PHRASES` (lib/jumpparse.js:93), the render chain in App.js (920-1020), BottomBar's key (46); server `Settings::VIEWS` and saved views' `viewType`. They agree today. A new view, or the per-device on/off and order of #61, has to reach every one. Rule: one table of views {id, label, icon, step (days or months), month-grid behaviour, narrow or wide only, typed phrases}, the server's enum read from the same list. Size: minus 40 plus 30, 1 to 2 hours. Tests: the jump parser and command tests under web/tests. Do it as the first step of #61.

## 7. "Is this stored value the same" (when next touched)

| | Undo::same / changedColumns (Undo.php:1273, 1232) | Feeds::sameValue (Feeds.php:413) | OwnFields::changedFields (Series/OwnFields.php:501) | GoogleSeries::masterBody (GoogleSeries.php:1394) / GoogleUndo::changedKeys (GoogleUndo.php:145) |
|---|---|---|---|---|
| NULL vs '' | different | different | **same** | same (cast to string) / JSON text |
| JSON column reformatted by MySQL | **text: differs** | exdates and invite decoded; other JSON as text | reminders normalized | n/a |
| integer read back as text | same (text) | all_day and parent id cast | text | text |
| coordinates | text | text | 9 decimals | n/a |

Some of this is real: "is the stored value identical" (Undo grounds) and "does the day show the same field" (own fields) are different questions, and NULL vs '' differs between them on purpose. The accident is storage identity being answered twice: `Feeds::sameValue` exists because "comparing JSON as text made every series with two or more skipped dates change on every poll" (pinned in run.php:8186-8190), and `Undo::same`, which decides every journal ground and so every stale-undo refusal, still compares JSON as text. Today both sides of an Undo comparison are usually read from the database, so it rarely bites; on MySQL 8, a snapshot built in PHP against a re-read row would ground an untouched JSON column and make a later undo read as stale. Candidate, not observed.

Rule: one storage-identity comparison keyed by column (`Undo::same($col, $a, $b)` with a small table: integer columns, JSON columns decoded and canonical, everything else as text), which `Feeds::sameValue` becomes; shown-field equality stays `OwnFields::changedFields` and `GoogleSeries::fieldsOf`. Check: an exhaustive table of column by value pairs, old function against new. Size: minus 30 plus 25, 1 hour. No stored form.

## 8. Activity lines for event changes (when next touched)

Local event entries take `Undo::defaultSummary` (Undo.php:2099), which names "the first after row that differs" ("Updated event 'X' (date)"); issue #223 fixed one case of it (a day edited on its own, pinned by run.php:8455-8464), and family 5 shows another it still misses. Google entries are hand-composed per path (Events.php:532, 537, 582, 752, 781, 854, 872; 1854, 2142, 2432 for local moves and answers). The same act reads differently on the two kinds of calendar. Rule, as decision 162 and 221 did for Google's series lines: compose from the action, the scope and the day (or days) acted on, the same composer for both kinds. Size: moderate (about 15 sites), 2 to 3 hours; mostly wording. Best done with family 5.

## 9. Scope dispatch and the write preamble (when next touched; partly #274)

Members: `patchLocked` (Events.php:1743, scope match 1882-1902), `deleteEventLocked` (2261, 2285-2316), `setAttendanceLocked` (2409), and their public wrappers `patch` (1585), `deleteEvent` (2241), `setAttendance` (2386), each repeating: lock the calendar, assert it is not moving (local), re-read and refuse `event_changed` ("This event changed calendars" at 1650, 2254, 2402), `refuseWhilePending`, then read-only checks by calendar kind, then `this` / `following` (split, else all) / `all`, each with a Google twin.

| | edit | delete | answer |
|---|---|---|---|
| scope missing on a series | 400 | `all` | `all` |
| scope validated in | domain | domain | controller (EventsController.php:226, 247) |
| `following` from the first day | all | all | all |

Whether a missing scope should delete the whole series while an edit refuses is the one difference that may be accidental; ask. Rule: one guarded entry (lock, recheck, pending, kind permission computed from the request's keys) and one scope resolver that returns `this`, a split, or `all`, each action supplying only its writer. Size: minus 60 plus 40, 2 to 3 hours. Tests: the series harness (all paths), `edits.test.php`. The Google half is in #274's reach.

## 10. Quick add text parser (when next touched)

`FallbackParser::extractDate` (FallbackParser.php:413-700) is per phrasing, which is the world's variety and fine. Two rules inside it are written per arm: "a date already past this year means next year" (decision 95) at 472, 492 and 585; "the next such weekday" at 514, 520 and 567. The copy at 567 ("a week from Friday") computes `% 7` without `?: 7`, so said on a Friday it counts from today, while "2 weeks from Friday" (514) and "Friday after next" (520) count from next Friday, as the client's jump parser does ("friday on a Friday means a week out"). One of the two readings is accidental; ask which. Rule: two helpers, called by the arms. Size: minus 25 plus 15, under an hour. Tests: `quickadd-golden.php`.

## 11. Google field map (covered by #274; inventory)

What Google holds of an event, and how each piece is sent, read and compared, is named in: `GoogleWriter::GOOGLE_COLUMNS` (GoogleWriter.php:60), `BODY_KEYS` (552), `body()` (433), `allowedKeys()` (564); `GoogleSync::toParsed` (GoogleSync.php:489, the reverse); in GoogleSeries.php `SYNCED` (116, equal to `Undo::GOOGLE_HELD` plus etag and own list), `FIELDS` (123, **unused**), `cols()` (156), `DAY_COLUMNS` (172, parallel to `BODY_KEYS`), `rowOf()` (1363), the column loop in `masterBody` (1396), the reverse loop in `afterMaster` (1814), `READ` (1593), `fieldsOf()` (1637), `GROUPS` and `OWN_GROUP` (2554-2558); `Undo::GOOGLE_HELD` (Undo.php:2013); `OwnFields::googleFields`/`HELD_HERE`. Decision 134 says every body is built in `GoogleWriter::patchOf`; `masterBody` computes its own difference column by column instead, over the same fields today, so a field added to `googleFields` (a colour, busy or free) would reach every writer but the master write. Three normalizers of "a row's Google fields" differ (`cols` keeps raw text and zone, `fieldsOf` sanitizes, trims and drops an all-day zone). For the rebuild: one field map (column, body key, read-back normalization, group, words) and `patchOf` for the master too.

## 12. Undo of a Google change by kind (covered by the roadmap; inventory)

`GoogleUndo::undoOne` (GoogleUndo.php:454) switches on the entry's kind: EDIT (483), DELETE (556), CREATE (606), ANSWER (637), plus SERIES (`undoSeries`, 720). Each arm reads, builds a body, sends, commits and records its inverse kind by hand (EDIT records EDIT, DELETE records CREATE, CREATE records DELETE). Beside it `Undo::apply`/`putBack` undo local changes from snapshots. The roadmap's "undo of plain fields on a Google calendar as a forward change back, on the same shared function" and 1.6's "undo on a calendar here moves onto the deltas model" are this collapse; the arms above are its inventory.

## Leave alone (examined; genuine variety or no gain)

- Month and weekday name tables: seven copies (Series/Words.php:17-19, FallbackParser.php:18-27, web/src/lib/repeat.js:12-20, planwords.js:27, jumpparse.js:22-24, seriesplan.js:12-13, EditorDrawer.js:47). Constants that never change, with different week starts and spellings per use; #147 (localization) is where they would meet.
- A rule in words, in PHP (`Series\Words::rule`) and in JS (`describeRepeat`, wrapped by `planwords.ruleWords` and `eventparts.describeRrule`): two runtimes that each need it. If anything, pin them with one shared fixture table; note planwords' `ruleShape` re-parses `describeRepeat`'s words by regular expression, which a wording change would break silently.
- Event page links (`EventPage`): one generic JSON-LD reader and one site source. Clean.
- Retention: `Feeds::retained` (the sync's question) and `OwnFields::own` (does a row own anything or is it referred to) are two questions; `Undo::retain` (Undo.php:3306) combines them into what `OwnFields::own` alone answers and could call it, a one-line tidy. Every `DELETE FROM events` of a day row goes through one of them or is a deletion the person asked for.
- Google's answers: status codes and reasons are read in one place (`GoogleWriter` 1020-1110, decision 150).
- Settings validation: one `match` table with a refusing default (Settings.php:418).
- Calendar views' event drawing: one `EventChip` used by every view.
- Client undo entry points: toast and Activity share `afterUndo` and `undoFailedWords`; the `WHOLE_SENTENCE` code list (api.js:858) is four codes, small enough to keep.
- The link rule and provider identity: already all-data invariants (188, 190).
- The series model's edit kinds (Series/Model.php, 17 match arms): measured by the series harness on every push; not a family to collapse by reading.
