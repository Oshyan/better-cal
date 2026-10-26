# Benchmarking Better-Cal

Maintainer notes on measuring this app. Most bad performance conclusions here came from a broken measurement, not a broken app, so assume the instrument is wrong before you assume the code is.

## Traps

### A Background Tab Lies About Frame Rate

A hidden tab may suspend animation callbacks and throttle timers, which looks like a busy main thread. Repeat a suspicious measurement in a visible tab before filing a performance issue, and **assert visibility inside the measuring script**, not by looking at the screen:

```js
const t0 = performance.now();
await new Promise(r => setTimeout(r, 16));
const drift = performance.now() - t0;       // want ~16-20, not 300+
let raf = false; requestAnimationFrame(() => { raf = true; });
await new Promise(r => setTimeout(r, 200));
// require: document.visibilityState === 'visible' && raf && drift < 40
```

### curl Doesn't Request Compression

`curl` doesn't ask for compression by default, so its numbers aren't comparable to a browser's. Send the header unless encoding is the question:

```bash
curl -s -o /dev/null -w '%{time_starttransfer} %{time_total}\n' \
  -H 'Accept-Encoding: gzip, br' -H "@$AUTH_FILE" "$URL"
```

### Reverse DNS Isn't a Location

A provider's reverse-DNS naming isn't evidence of where a server is. Measure latency; don't infer it from a name. Keep hostnames, addresses and network layout from a real install out of these notes.

### Compare Visuals at Shipping Size

A screenshot viewed at a different zoom than the app can make a 2px line look like 3px. When comparing visual weight, match the app's viewport and device pixel ratio.

### Compare Two Apps in One Browser

Numbers from the in-app Browser pane aren't comparable to numbers from Chrome (different profile, extensions, cache, window size and background load). Measure **both** products in the same browser, back to back, and note any difference in test data, since the number of visible events can matter more than the timings.

### Sub-Linear Scaling Means a Fixed Cost Is Hiding

If a much wider event window takes only slightly longer to query, look for work done once per request rather than once per returned event. For example, sabre's `fastForward` may walk a recurring series from its DTSTART to the window once per master, per request. **Always measure at least three window sizes**, with invented test data: the shape of the curve tells you where to look faster than a profiler does.

### Layout Changes Break Code That Reasons About Layout

Making month rows taller once broke month navigation: `stepAnchor` steps from the dominant visible month, computed over `round(viewH / rowH)` rows, so fewer, taller rows let a mostly off-screen row carry the vote and Next-month appeared to do nothing. After any change to how much is on screen, re-test month stepping, the toolbar label, the mini-month and window demand.

### Check Whether the Work Already Exists

Search `docs/` before scoping new analysis. An audit was once scoped while a better one already sat in `docs/security-review-2026-08-04.md`.

## Tools in the Repo

```bash
# Server-side phase breakdown of the events window: SQL, expansion, sort,
# filters, labels/trips, json_encode, plus a scaling table.
php server/bin/profile-events.php --months=5 --runs=3 [--user=1]

# Per-master recurrence cost, split into building the sabre object,
# fast-forwarding to the window, and walking inside it.
php server/bin/profile-recurrence.php --months=5 --top=15 [--user=1]
```

Both read the app's database and need its installed dependencies, so run them on the server.

## Recording a Baseline

- Use an invented fixture and record its shape with each result: a mix of ordinary events, repeating series, exceptions, all-day events and empty calendars. A recurrence-heavy fixture answers a different question from a mixed calendar.
- Measure server query time, recurrence expansion, serialization, transfer, parsing and rendering separately. Measure low-end clients separately when that's the question.
- Repeat with the same fixture and conditions before attributing a change to code.
- Keep real-install workload figures, activity patterns, hosting details and timings out of the repository.

## Choosing What to Optimize

Optimize the measured bottleneck. SQL, serialization, browser parsing and network latency can each dominate under different conditions, and none is always negligible. Keep fixtures and acceptance thresholds in the test plan rather than treating one install as representative.
