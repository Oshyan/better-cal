# Docs-only authoring test

Before open-sourcing the plugin system, two agents each built a real plugin from `../authoring.md` alone. Neither could read the app source, the `PluginHost` implementation, or the five bundled plugins; both had a live instance, an API token, and the guide. They worked independently of each other and logged friction as they hit it.

The point was not to get two more plugins. It was to find out what the guide fails to say, by making someone rely on it with no fallback. Both plugins shipped and both are in `server/plugins/`, but the findings below are the deliverable.

- [visit-intents-findings.md](visit-intents-findings.md)
- [trip-planner-findings.md](trip-planner-findings.md)

## What it caught

One real code defect, found independently by both authors: `PluginHost::geocode()` read `Geocode::lookup()`'s answer as `$hits[0]`, but `lookup()` returns a single `{lat,lng,display}` map with no index `0`. Every plugin geocode had silently returned null for the whole life of v1 and v2. It was invisible from inside the project because the HTTP `/geocode` endpoint uses `lookup()` correctly, so the feature demonstrably worked everywhere except the one path plugins use. Pinned by three checks in `server/tests/run.php`.

The documentation gaps that cost the most, all now folded into the guide:

| Gap | Why it hurt |
|---|---|
| `text` settings truncate at 500 chars, silently, with a 200 response | A too-long setting looks saved, and the job then runs on partial data and produces plausible wrong output |
| `validateSettings()` receives only the keys being patched | The guide's own example made settings permanently unsavable; both authors hit it |
| No way to withdraw a proposal | Both authors independently invented the same kv generation-counter workaround |
| The sanitizer allowlist was described as "the same allowlist as event descriptions" | That points into source a third party cannot read; headings and tables silently collapse |
| A run is not a transaction | Writes before a throw survive, which breaks the "already announced" fingerprint pattern the guide recommends |
| `replaceRanges` documents a 2000 write cap but reads back at most 500 per window | Bands past 500 are stored and never seen |

Also worth recording: a host UI bug the review pass caught rather than the authors, since they had no browser. The plugin info card clamped its x position to the viewport but not its y, so a band low on screen opened a card that ran off the bottom with the overflow unreachable. Fixed in `App.js` (flip above the anchor) and `app.css` (cap height, scroll).

## Doing this again

Worth repeating whenever the host API changes shape. What made it work:

- **No repo access.** The moment an author can read a working plugin, they stop reading the docs and the test stops measuring anything.
- **Product-level briefs.** Describe what the user wants, never which method to call, or you pre-solve the parts the docs are supposed to carry.
- **Log friction at the moment it happens.** A report written from memory at the end is uniformly bland.
- **No browser.** Two agents on one instance would collide, and headless verification via the API turned out to be enough for the authors. Keep the visual pass for the reviewer.
- **Ask for calibration.** Requiring authors to name what the docs got *right* keeps the report from being an undifferentiated pile of complaints.
