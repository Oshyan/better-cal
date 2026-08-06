# Benchmarking Better-Cal

Notes from measuring this app, written down because every one of these cost real
time to learn and one of them produced a bug report for a bug that did not exist.

The theme: **most bad performance conclusions here came from a broken
measurement, not a broken app.** Assume the instrument is wrong before you
assume the code is.

## Traps that have actually bitten

### A background browser tab lies about frame rate

Chrome suspends `requestAnimationFrame` entirely in a hidden tab and throttles
`setTimeout` hard — measured on this app, a 16ms timer took **372ms**. Both
symptoms read exactly like a stalled main thread: zero frames captured, and
instrumentation scripts blowing their timeouts because inner loops run 20-40x
slower than written.

This produced a filed issue claiming month scrolling froze the UI. It does not;
re-measured visible, it holds a 16.6ms median frame with zero frames over 32ms.

**Always assert visibility inside the measuring script**, not by looking at the
screen:

```js
const t0 = performance.now();
await new Promise(r => setTimeout(r, 16));
const drift = performance.now() - t0;       // want ~16-20, not 300+
let raf = false; requestAnimationFrame(() => { raf = true; });
await new Promise(r => setTimeout(r, 200));
// require: document.visibilityState === 'visible' && raf && drift < 40
```

### curl does not request compression

`curl` sends no `Accept-Encoding` unless told, so it measures the **uncompressed**
path. Browsers get brotli. On the events window that is 2.8 MB versus 188 KB —
about 400ms of difference, which is larger than most things you would be trying
to measure.

```bash
curl -s -o /dev/null -w '%{time_starttransfer} %{time_total}\n' \
  -H 'Accept-Encoding: gzip, br' -H "$AUTH" "$URL"
```

Compare encodings deliberately when that is the question; otherwise always send
the header, because that is what a real client does.

### Reverse DNS is not a location

Hetzner puts `.your-server.de` on every machine it owns regardless of continent.
This server is in **US East (Virginia)**, ~81ms RTT from the Bay Area, and was
confidently described as German on the strength of that hostname.

`traceroute` answers it in seconds — watch the city codes in the hop names.

### Measure at the size the thing ships at

A design comparison rendered in a viewer at a different zoom than the app made a
2px underline look like 3px, and led to a whole exchange about a thickness
difference that did not exist. If you are comparing visual weight, match the
app's viewport and device pixel ratio, or you are comparing screenshots rather
than designs.

### Comparing two apps means comparing them in one browser

Numbers from the in-app Browser pane are not comparable to numbers from Chrome:
different profile, extensions, cache state, window size, and whatever else is
running. When benchmarking against another product, measure **both** in the same
browser, back to back, and say what the dataset difference was — chip counts
rarely match, and that usually matters more than the timings.

### Sub-linear scaling means a fixed cost is hiding

The events query looked "slow for large windows". It was not: 1 month cost 376ms
and 12 months cost 582ms. Eleven times the work for 1.5x the time means almost
all of it was fixed overhead — in this case sabre's `fastForward` walking a
recurring series from its DTSTART to the window, once per master, per request.

**Always measure at least three window sizes.** The shape of the curve tells you
where to look far faster than a profiler does.

### Changing layout changes everything that reasons about layout

Raising month row height from ~116px to 182px silently broke month navigation:
`stepAnchor` steps from the *dominant visible month*, which is computed over
`round(viewH / rowH)` rows. Fewer, taller rows changed that count, a mostly
off-screen row started carrying the vote, and Next-month appeared to do nothing.

The row geometry itself was verified carefully. Nothing that *depended* on row
count was re-tested. After any change to how much is on screen, re-run month
stepping, the toolbar label, the mini-month, and window demand.

### Check whether the work already exists

A security audit was proposed and scoped before anyone noticed
`docs/security-review-2026-08-04.md` already existed, with better analysis.
Search `docs/` first.

## Tools in the repo

```bash
# Server-side phase breakdown of the events window: SQL, expansion, sort,
# filters, labels/trips, json_encode, plus a scaling table.
php server/bin/profile-events.php --months=5 --runs=3

# Per-master recurrence cost, split into building the sabre object,
# fast-forwarding to the window, and walking inside it.
php server/bin/profile-recurrence.php --months=5 --top=15
```

Both run against real data and must be run on the server, since they need the
app's database credentials and its installed dependencies.

## Baselines

Measured 2026-08-06 on the production box (4-core EPYC-Milan, ~40% loaded),
from a Bay Area client at ~81ms RTT. Conditions stated because they matter.

| what | value | conditions |
|---|---|---|
| `Events::window` | 167ms median | 5-month window, 3,585 occurrences, Todoist on |
| — recurrence expansion | 105ms | the dominant phase; SQL is only 15ms |
| `/events` end to end | 476ms | browser, brotli; TTFB 317, body 171, parse 5 |
| cold load, fully loaded | ~950ms | warm HTTP cache, 1500x800 |
| — first contentful paint | ~436ms | |
| — modules finished | ~370ms | 61 modules, modulepreload generated |
| month scroll | 16.6ms median frame | 0 frames >32ms, 1500x800, Todoist on |
| month step (next/prev) | 16-28ms | |
| payload | 793 bytes/occurrence | ~30% of it detail-only fields (see #15) |
| brotli ratio | 15x | 2.8 MB → 188 KB on a 5-month window |

If a number here moves by more than about 30%, something changed — check the
measurement before concluding it was the code.

## Things that are not worth optimising

Recorded so they are not rediscovered:

- **SQL.** Masters plus overrides is 15ms; the window-overlap query is a full
  table scan over 8,400 rows at 10ms. Indexing it would win nothing.
- **`JSON.parse` on the client.** 5ms for 2.8 MB on a desktop. Worth revisiting
  only for low-end mobile.
- **`json_encode` server-side.** ~10ms.
- **Round-trip latency.** ~81ms of every request is the speed of light to
  Virginia and back. Not a configuration problem.
