# Loading the Calendar's Events

How the web app knows which events it holds, whether they're current, and what it asks the server again, written down for 1.5 before the code was rewritten (2026-11-02). It replaces about eight overlapping mechanisms found by the structure review of the fixes since 1.4.0 ([#190](https://github.com/Oshyan/better-cal/issues/190)), and the change check's three kinds of "still owed". Line numbers are at 16d4d43. Every example is invented.

## Generation

The store keeps one number, the generation: this tab's name for the newest state of the user's events it knows the server has. It goes up when a save made here lands and its refresh begins (`refreshWindow`), and when the change check sees the `cursor` part move, unless that move is this tab's own save, already refetched after reading it. Nothing else moves it.

## Loaded and Fresh

`state.loadedRanges` lists every stretch held, each with the generation it was asked for at. A stretch is loaded when it's in that list, and fresh when its generation is the current one. Moving the generation makes everything held stale at once without dropping it: it stays on screen and is asked for again when it's next needed.

A request asked at generation 4 that lands after a stretch overlapping it was held at 5 is dropped whole: it's older than what's there. Otherwise it's merged and its stretch held at 4, fresh only if 4 is still current.

## One Fetch Path

Everything that needs events asks through one function, `ensure(start, end)`: the view's window, a trip's stretch, a day an open event steps through, the agenda's next stretch, a save's refresh, a retry. It works out what's missing (not fresh, and not already on its way at the current generation), widens each gap to whole months, and fetches them one at a time. A month the server refuses as too many rows is asked for again as just the days needed. Requests already on their way that it relies on are waited for, then it looks again and fetches whatever they didn't bring, until nothing is missing. If the generation moves meanwhile, it carries on at the new one. A failed request ends it with the failure.

Each request goes through `fetchRange`, which knows the per-series cap. The server expands at most 1,000 of one series a request and names a series it cut short in `capped`. Its answer is then complete up to that series' last occurrence returned, so `fetchRange` holds the stretch up to there and asks for the rest in a next request, up to twelve in all. An hourly series over five months is four requests and every occurrence. Only a series too dense for that (every minute, say) is kept as answered, and a toast says some of its occurrences aren't shown.

## Local Changes

A drag, a resize, a Maybe or a delete shows at once. The occurrence gets a local mark: pending while its save is on its way, then, once the save lands, "until an answer asked from the next generation". A merged answer leaves a marked occurrence as it is here (moved, changed or gone) unless the answer was asked at or after that generation and its stretch overlaps the occurrence; that answer replaces it and clears the mark. A save that fails puts back what was there, held the same way until an answer asked after the next change (an earlier edit's save may still be on its way). So an answer asked before a save lands can never put the old version back, and only a fetch that carries the save ends the mark. An edit also moves the record generation, so an event's full record asked before it is asked again. An occurrence opened on its own (from Activity, a notification) isn't marked: it's what the server has, and its open panel keeps it.

## A Failed Fetch

Nothing is marked loaded that didn't land: the stretch stays unloaded, or held at its older generation. When the failure is the view's, the view's window is owed a reload and a note says "Could not load events. Retrying…" (or that the device is offline and these dates aren't saved on it), with Retry now. It's retried with the change check's other owed reloads: whenever a check reaches the server (every 30 seconds while the page is in view, at once on focus or coming back online), or on Retry now. A load that ends with the view's window fresh clears the note, whichever request brought the last of it. A window the server can never answer (too wide, too many rows) says so and isn't retried unchanged.

## Owed Reloads

One set (`reloads.js`) holds every reload owed, by name: `window`, `calendars`, `people`, `views`, `settings`, `review`, `status`. What the change check's three parts owe:

- `cursor` moved: `window` (unless it's this tab's own save, already refetched), `people`, `views`, `settings`, `calendars`, `review`; fetched records are marked out of date.
- `review` moved: `review`.
- `status` moved: `calendars` and `status` (system health, plugins and their bands, updates).

A reload that fails or doesn't apply (the calendar list while a write is unsaved, settings while the Settings page is open) stays owed. Every check that reaches the server runs everything owed, together. A write to the calendar list owes `calendars`, and an undo owes the lists it may have changed.

A check doesn't wait for reloads still on their way, so it can see the cursor move again while one runs. A reload owed while it's already running isn't started twice: it runs once more when the running one settles, however many times it was owed meanwhile, and the newest answer wins. A run settles only what was owed before it began. The window's reload carries on at the new generation by itself (see One Fetch Path), so the run after it usually finds nothing left to fetch. There are no timers: the check's own cadence is the only clock.

## What Replaces What

1. Optimistic marks (`_optimistic`: store.js:305, :366-383; actions.js:404-433) and
2. edit times (`editedAt`, `noteEdited`, `editedSince`: store.js:289-305, :479, :491, :499, :506; api.js:721): one local mark per occurrence (`patchOccurrence`, `removeOccurrence`, `localSaved`, `restoreOccurrence`), ended only by an answer that carries its save. A record asked before a local edit is asked again through the record generation the edit moves.
3. Stale ranges (`staleRanges`, `markStaleOutside`, `markStale`: store.js:97, :345, :397-422; api.js:395, :760, :788): the generation on each loaded stretch.
4. The refresh cut-off (`lastRefreshAsked` and the re-ask from inside `fetchRange`: api.js:315-317, :386-392, :756): answers older than what's held are dropped by generation.
5. Requests that wait on others (`awaitComing` and its recursion: api.js:599-610, :616, :652): `ensure`'s one loop.
6. Failed windows with their own retry (`failedWindows`, its timer and back-off, `settleViewNote`, `windowLoaded`, `retryWindowsNow`, the online listener: api.js:409-519): the view's window owed, retried with the other owed reloads.
7. The change check's own flags (`windowOwed`, `restOwed`: App.js:244-280): the owed set.
8. Request counters (`windowReqId`, `latestRefresh` and `refreshWindow`'s own fetch loop, which ignored `capped`: api.js:314, :318-319, :761-795): `refreshWindow` moves the generation and runs the `window` reload through `ensure`. The dead `force` branch and `abortSubsumed` (api.js:329-338, :570-582) are gone.

For the change check: `serialReloads` (api.js:798-818) and `calendarsDirty`/`calendarsOwed` (api.js:192-244) are the owed set. The check itself moves from App.js into `changecheck.js`; App.js keeps when it runs.
