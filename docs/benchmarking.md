# Benchmarking Better-Cal

Notes from measuring this app, written down because every one of these cost real time to learn and one of them produced a bug report for a bug that did not exist.

The theme: **most bad performance conclusions here came from a broken measurement, not a broken app.** Assume the instrument is wrong before you assume the code is.

## Traps that have actually bitten

### A background browser tab lies about frame rate

A hidden browser tab may suspend animation callbacks and throttle timers. Check visibility before interpreting slow measurements as a busy main thread.

Repeat a suspicious measurement in a visible tab before filing a performance issue.

**Always assert visibility inside the measuring script**, not by looking at the screen:

```js
const t0 = performance.now();
await new Promise(r => setTimeout(r, 16));
const drift = performance.now() - t0;       // want ~16-20, not 300+
let raf = false; requestAnimationFrame(() => { raf = true; });
await new Promise(r => setTimeout(r, 200));
// require: document.visibilityState === 'visible' && raf && drift < 40
```

### curl does not request compression

`curl` does not ask for compression by default. Compare it with the browser only when both request the same encoding.

```bash
curl -s -o /dev/null -w '%{time_starttransfer} %{time_total}\n' \
  -H 'Accept-Encoding: gzip, br' -H "@$AUTH_FILE" "$URL"
```

Compare encodings deliberately when that is the question; otherwise always send the header, because that is what a real client does.

### Reverse DNS is not a location

A provider's reverse-DNS naming convention is not evidence of where a server is located. Network latency must be measured, not inferred from a name.

Do not retain hostnames, addresses or network layout from a real install in public benchmark notes.

### Measure at the size the thing ships at

A design comparison rendered in a viewer at a different zoom than the app made a 2px underline look like 3px, and led to a whole exchange about a thickness difference that did not exist. If you are comparing visual weight, match the app's viewport and device pixel ratio, or you are comparing screenshots rather than designs.

### Comparing two apps means comparing them in one browser

Numbers from the in-app Browser pane are not comparable to numbers from Chrome: different profile, extensions, cache state, window size, and whatever else is running. When benchmarking against another product, measure **both** in the same browser, back to back. Describe any differences in the test data, since the number of visible events can matter more than the timings.

### Sub-linear scaling means a fixed cost is hiding

If a much wider event window takes only slightly longer to query, look for work that happens once per request rather than once per returned event. For example, sabre's `fastForward` may walk a recurring series from its DTSTART to the window once per master, per request. Compare several window sizes using invented test data before choosing what to optimise.

**Always measure at least three window sizes.** The shape of the curve tells you where to look far faster than a profiler does.

### Changing layout changes everything that reasons about layout

Raising month row height from ~116px to 182px silently broke month navigation: `stepAnchor` steps from the *dominant visible month*, which is computed over `round(viewH / rowH)` rows. Fewer, taller rows changed that count, a mostly off-screen row started carrying the vote, and Next-month appeared to do nothing.

The row geometry itself was verified carefully. Nothing that *depended* on row count was re-tested. After any change to how much is on screen, re-run month stepping, the toolbar label, the mini-month, and window demand.

### Check whether the work already exists

A security audit was proposed and scoped before anyone noticed `docs/security-review-2026-08-04.md` already existed, with better analysis. Search `docs/` first.

## Tools in the repo

```bash
# Server-side phase breakdown of the events window: SQL, expansion, sort,
# filters, labels/trips, json_encode, plus a scaling table.
php server/bin/profile-events.php --months=5 --runs=3

# Per-master recurrence cost, split into building the sabre object,
# fast-forwarding to the window, and walking inside it.
php server/bin/profile-recurrence.php --months=5 --top=15
```

Both run against real data and must be run on the server, since they need the app's database credentials and its installed dependencies.

## Recording a baseline

Use an invented fixture and record its shape with each benchmark: a mix of ordinary events, repeating series, exceptions, all-day events and empty calendars. A recurrence-heavy fixture answers a different question from a mixed calendar. Keep real-install workload figures, activity patterns, hosting details and timings out of the repository.

Measure server query time, recurrence expansion, serialization, transfer, parsing and rendering separately. Repeat with the same fixture and conditions before attributing a change to code. Measure low-end clients separately when that is the question.

## Choosing what to optimise

Optimise the measured bottleneck. SQL, serialization, browser parsing and network latency can each dominate under different conditions; none is universally negligible. Keep benchmark fixtures and acceptance thresholds in the test plan rather than treating one install as representative.
