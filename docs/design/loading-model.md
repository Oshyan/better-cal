# Loading the Calendar's Events

How the web app knows which events it holds, whether they're current, and what it asks the server again, written down for 1.5 before the code was rewritten (2026-11-02). It replaces about eight overlapping mechanisms found by the structure review of the fixes since 1.4.0 ([#190](https://github.com/Oshyan/better-cal/issues/190)), and the change check's three kinds of "still owed". Line numbers are at 16d4d43. Every example is invented.

## Generation

The store keeps one number, the generation: this tab's name for the newest state of the user's events it knows the server has. It goes up when a save made here lands and its refresh begins (`refreshWindow`), and when the change check sees the `cursor` part move, unless that move is this tab's own save, already refetched after reading it. Nothing else moves it.

## Loaded and Fresh

`state.loadedRanges` lists every stretch held, each with the generation it was asked for at. A stretch is loaded when it's in that list, and fresh when its generation is the current one. Moving the generation makes everything held stale at once without dropping it: it stays on screen and is asked for again when it's next needed.

A day held at an older generation is shown but not fresh. It's drawn as it was, it counts as loaded for everything that reads what's held (the agenda's list, the split view), and `ensure` asks for it again, quietly: the "Loading events" note is only for days on screen that aren't held at all (never loaded, or dropped from the cache), or a cold load. So a save's refresh and a change from another device redraw days already showing without a note over them.

A request asked at generation 4 that lands after a stretch overlapping it was held at 5 is dropped whole: it's older than what's there. Otherwise it's merged and its stretch held at 4, fresh only if 4 is still current. The same goes for each occurrence: one running from the answer's stretch into a stretch held at a newer generation is left as that newer answer had it, there or gone. The older answer doesn't replace it, remove it, or bring back its own copy (an event deleted elsewhere stays deleted).

## One Fetch Path

Everything that needs events asks through one function, `ensure(start, end)`: the view's window, a trip's stretch, a day an open event steps through, the agenda's next stretch, a save's refresh, a retry. It works out what's missing (not fresh, and not already on its way at the current generation), widens each gap to whole months, and fetches them one at a time. Just before each request it looks again at what's on its way, so a month another load asked for meanwhile isn't asked for twice. A month the server refuses as too many rows is asked for again as just the days needed. Requests already on their way that it relies on are waited for, then it looks again and fetches whatever they didn't bring, until nothing is missing. If the generation moves meanwhile, it carries on at the new one. A failed request ends it with the failure.

The view's own load is the one exception to carrying on: once the view has moved to another window, it stops before its next request (the new window's load asks for what that needs). It's also the only load that keeps the cache under its ceiling, pruning to its own window: pruned to a newer one, an older window found its stretch gone after every request and asked again without end.

Each request goes through `fetchRange`, which knows the per-series cap. The server expands at most 1,000 of one series a request and names a series it cut short in `capped`, but only one the answer shows some of its own occurrences of (a series a filter hides entirely isn't named). Its answer is then complete up to the earliest of those series' last occurrences returned, so `fetchRange` holds the stretch up to there and asks for the rest in a next request, up to twelve in all. An hourly series over five months is four requests and every occurrence. Twelve requests reach about 12,000 occurrences of one series a stretch: over the agenda's 210 days, loaded in one go, that's a series more often than about every 25 minutes. Past that it's kept as answered, and a toast names it, from the answer, saying some of its occurrences aren't shown. A capped series with none of its own occurrences in the answer (an older server naming one a filter hid, or only edited dates of it showing) is passed over: it shows nothing that could be cut short.

## Local Changes

A drag, a resize, a Maybe or a delete shows at once. The occurrence gets a local mark, which lists its saves in the order made and what was held before the first of them. A single event, or an edited day of a series, is marked by its event: its instanceId carries its start, so a moved one comes back under a new name, and marked by that name it was drawn twice. A series' own occurrence is marked by its instanceId (series and day).

The mark is pending while any of its saves is on its way. Once the last of them settles with one landed, it's "until an answer asked from the generation after the last landing". A merged answer leaves a marked occurrence as it is here (moved, changed or gone), and doesn't add its own copy, unless the answer was asked at or after that generation and its stretch overlaps the occurrence; that answer replaces it and clears the mark. So with two saves on their way, the first one landing doesn't end the mark, and its refresh (asked before the second reached the server) can't put the first one's version back.

A save that fails comes off the mark. If it wasn't the latest, the later save's copy stays on screen; if it was, the occurrence shows the latest save still standing (on its way or landed) or, with none, what was held before any of them. Once none is on its way: with one landed, the mark holds until an answer carries it; with none landed, the mark goes, since what's shown is the server's own copy, not a local change, and the next answer may replace it. So an answer asked before a save lands can never put the old version back, a failed save never holds its position on screen, and only a fetch that carries the latest landed save ends the mark. An edit also moves the record generation, so an event's full record asked before it is asked again. An occurrence opened on its own (from Activity, a notification) isn't marked: it's what the server has, and its open panel keeps it.

A series' occurrence moved on its own day becomes an edited day with an event of its own, which the save's answer doesn't name. An answer asked before that save landed and handled after the server made the edited day can draw it beside the local copy until the save's refresh lands.

## A Failed Fetch

Nothing is marked loaded that didn't land: the stretch stays unloaded, or held at its older generation. When the failure is the view's, the view's window is owed a reload and a note says "Could not load events. Retrying…" (or that the device is offline and these dates aren't saved on it), with Retry now. It's retried with the change check's other owed reloads: whenever a check reaches the server (every 30 seconds while the page is in view, at once on focus or coming back online), or on Retry now. A load that ends with the view's window fresh clears the note, whichever request brought the last of it. A window the server can never answer (too wide, too many rows) says so and isn't retried unchanged.

## Owed Reloads

One set (`reloads.js`) holds every reload owed, by name: `window`, `calendars`, `people`, `views`, `settings`, `review`, `status`, `plugins`. What the change check's three parts owe:

- `cursor` moved: `window` (unless it's this tab's own save, already refetched), `people`, `views`, `settings`, `calendars`, `review`; fetched records are marked out of date.
- `review` moved: `review`.
- `status` moved: `calendars`, `status` (system health and updates) and `plugins` (the plugins list, then their bands, refetched once the list lands).

A reload that fails or doesn't apply (the calendar list while a write is unsaved, settings while the Settings page is open) stays owed. Every check that reaches the server runs everything owed, together. A failing health read is asked again at each check, on its own: plugins and their bands are refetched once per move of the `status` part. A write to the calendar list owes `calendars`, and an undo owes `review`, `calendars`, `people`, `views` and `settings`, which it may have changed (settings like the change check's: not while the Settings page is open).

A check doesn't wait for reloads still on their way, so it can see the cursor move again while one runs. A reload owed while it's already running isn't started twice: it runs once more when the running one settles, however many times it was owed meanwhile, and the newest answer wins. A run settles only what was owed before it began. The window's reload carries on at the new generation by itself (see One Fetch Path), so the run after it usually finds nothing left to fetch. There are no timers: the check's own cadence is the only clock.

## What Replaces What

1. Optimistic marks (`_optimistic`: store.js:305, :366-383; actions.js:404-433) and
2. edit times (`editedAt`, `noteEdited`, `editedSince`: store.js:289-305, :479, :491, :499, :506; api.js:721): one local mark per occurrence (`patchOccurrence`, `removeOccurrence`, `localSaved`, `restoreOccurrence`), ended only by an answer that carries its latest landed save (by event for a single event or an edited day). A record asked before a local edit is asked again through the record generation the edit moves.
3. Stale ranges (`staleRanges`, `markStaleOutside`, `markStale`: store.js:97, :345, :397-422; api.js:395, :760, :788): the generation on each loaded stretch.
4. The refresh cut-off (`lastRefreshAsked` and the re-ask from inside `fetchRange`: api.js:315-317, :386-392, :756): answers older than what's held are dropped by generation, by stretch and by occurrence.
5. Requests that wait on others (`awaitComing` and its recursion: api.js:599-610, :616, :652): `ensure`'s one loop.
6. Failed windows with their own retry (`failedWindows`, its timer and back-off, `settleViewNote`, `windowLoaded`, `retryWindowsNow`, the online listener: api.js:409-519): the view's window owed, retried with the other owed reloads.
7. The change check's own flags (`windowOwed`, `restOwed`: App.js:244-280): the owed set.
8. Request counters (`windowReqId`, `latestRefresh` and `refreshWindow`'s own fetch loop, which ignored `capped`: api.js:314, :318-319, :761-795): `refreshWindow` moves the generation and runs the `window` reload through `ensure`. The dead `force` branch and `abortSubsumed` (api.js:329-338, :570-582) are gone.

For the change check: `serialReloads` (api.js:798-818) and `calendarsDirty`/`calendarsOwed` (api.js:192-244) are the owed set. The check itself moves from App.js into `changecheck.js`; App.js keeps when it runs.
