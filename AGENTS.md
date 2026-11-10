# Working on Better-Cal

Read this before changing code, whether you are a person or an agent. It holds the standing rules that are not derivable from the code. The reasoning behind each lives in [docs/design/decisions.md](docs/design/decisions.md); the plan lives in [ROADMAP.md](ROADMAP.md); how to run the tests is in [CONTRIBUTING.md](CONTRIBUTING.md).

## Stored data: no compatibility code, no rewrites

Better-Cal allows breaking changes before 2.0, and it does not carry compatibility code for stored data that ages out.

- **A change to a stored form is a version bump, never a rewrite of old rows.** When how something is stored changes (an undo entry's grounds, a pending change's plan, a key compared across entries), the new form gets a format number, new rows carry it, and rows in an older format are refused or read-only with a reason shown to the person. Precedent: `mutations.format` and `Undo::JOURNAL_FORMAT`; migration 077 makes older undo entries log only.
- **Never write a migration that transforms old data into a new form** without the owner's explicit choice. Bring the data question first: what old data exists, how long it lives, what refusing it costs. Then ask. The default proposal is a format gate.
- **Data corrections are not compatibility code.** A migration that fixes real calendar rows (cleaning stored HTML, converting all-day dates, filling a missing column from the row's own state) stays. The line: a correction makes a live row right; compatibility code keeps two readings of the same thing alive.
- **Never add a fallback that reads an older form** next to the current one ("if the list is null, infer it"). If a writer still produces the old form, that is a bug in the writer; file it and fix the writer. If old rows exist, a one-time backfill fills them and the fallback goes.
- **Guards for code running ahead of its migrations are dead.** The app refuses to serve while migrations are pending, so an "if the column exists" branch serves nothing in production. Do not add one; fix the test schema instead.
- **Every release's notes say what an upgrade loses**, or that it loses none. The oldest version an install may update from is 1.0; code that only serves older installs is removed.

Why this is strict: in November 2026 a migration that rewrote older undo entries into the new form cost about six hours of agent time and a thousand lines, failed two outside reviews (a memory blowup over a large deletion, then an old series move read as current), and was replaced by a sixty-line format gate that deleted 865 lines. The gate costs a person at most a week of undo for changes made before the upgrade. The rewrite could silently restore the wrong thing.

## Fixes follow from structure

- **One rule, one function.** A fix is a rule the code applies everywhere, never a branch per case. When a fix needs a second implementation of something that exists, delete one.
- **Stop on neighbours.** If fixing one case reveals a neighbouring case, stop and restate the rule; do not fix the cases one at a time.
- **A rule is not done until its siblings are inventoried.** When a fix establishes the one function or the one shape every path must use (every writer journals the links it changes; every day row stores its own list; every removal goes through the retention function), list every other path that should follow it, in the same change, and say for each that it does, or fix it, or file it. A golden path with uninventoried siblings is where the next review finding comes from: in November 2026 two of the three findings after a rule existed were writers that predated the rule and had never been checked against it.
- **Never redesign a mechanism inside the fix loop.** A new mechanism needs a written model (what it stores, what it compares, what it refuses) and a check that can fail on injected damage before any code changes.
- **Every decision gets an entry** in [docs/design/decisions.md](docs/design/decisions.md) in the same commit: what was chosen, what else was possible, what it costs, how it was verified.
- **Do not trade diligence for speed on your own.** If a schedule seems to call for less testing, a smaller gate, or a shortcut, ask the owner; never decide it alone.

## Before a push

Every gate completes before any push to `main`: the unit suites, the database suite, both modes of the series harness at full case counts, and the interleaving mode (see [CONTRIBUTING.md](CONTRIBUTING.md) and [tools/edit-harness/README.md](tools/edit-harness/README.md)). `tools/gates.sh full` is that set, run once on the final tree. A gate that has not finished is a gate that has not passed.

The full set is for the push, not for every step. While working, run `tools/gates.sh quick` with the test files near the change; after a merge, `tools/gates.sh merge`. A worker does not run seeds or the mutation list, and does not chase `main`: it hands back on the commit it was given, and the merge is gated on `main` once. Do not add a seed, a mode or a configuration to the full set because a review round used it; a recorded seed is a regression test and one is enough, and breadth belongs in background runs that block nothing ([CONTRIBUTING.md](CONTRIBUTING.md), "Gates: Three Levels").

## Privacy

Nothing in this repository describes a real person's data. Tests, fixtures, docs, examples, commit messages and issues use invented calendars, events, people, places and hosts. Describe behaviour in general terms. Values a deployment needs live in untracked config, never in tracked files.
