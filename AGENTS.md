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
- **Compute from the data, never from a list of cases.** When code has to say what a change does (what is lost, what differs, what must be sent), it compares the state before with the state after and reports the difference. It does not go through kinds of change one at a time. A list of cases is only as complete as its author thought to make it: in November 2026 a feature that listed its losses by kind of half-done change was broken twice by independent readers, each time by a kind the list had missed. The same holds for a brief and for a review: ask for the comparison, and treat an enumeration as a finding.
- **Stop on neighbours.** If fixing one case reveals a neighbouring case, stop and restate the rule; do not fix the cases one at a time.
- **A rule is not done until its siblings are inventoried.** When a fix establishes the one function or the one shape every path must use (every writer journals the links it changes; every day row stores its own list; every removal goes through the retention function), list every other path that should follow it, in the same change, and say for each that it does, or fix it, or file it. A golden path with uninventoried siblings is where the next review finding comes from: in November 2026 two of the three findings after a rule existed were writers that predated the rule and had never been checked against it.
- **Never redesign a mechanism inside the fix loop.** A new mechanism needs a written model (what it stores, what it compares, what it refuses) and a check that can fail on injected damage before any code changes.
- **Every decision gets an entry** in [docs/design/decisions.md](docs/design/decisions.md) in the same commit: what was chosen, what else was possible, what it costs, how it was verified.
- **Do not trade diligence for speed on your own.** If a schedule seems to call for less testing, a smaller gate, or a shortcut, ask the owner; never decide it alone.

## Sort what was found by likelihood, before fixing any of it

- **Before any fix starts, sort the findings by how likely their cause is to occur in normal use.** This holds for every source: an outside review, a worker's report, a harness seed, an issue, a proposal of your own. For each one, write out what a person would have to do, in what order, for it to happen, and say how often that is.
- **Likely in normal use: fix it.** Unlikely (two or three uncommon things in a row, a request the app never sends, a state only a race between a poll and a stale tab produces): bring it to the owner as "file or fix?" with the sequence written out, before any work starts. The default is to file it.
- **A severity label is not a likelihood.** A reviewer's P2 says how bad the result is, not how often it happens. "Reproduced, in range, and a wrong value saved" is not by itself a reason to fix: the reproduction shows it can happen, the sequence shows whether it will.
- **A silently wrong result raises the bar for filing, it does not remove the question.** Silent loss in a likely case is always a fix. Silent loss in an unlikely case is still the owner's call.
- **The same sort applies to what a fix turns up.** A neighbour found while fixing is sorted like any other finding; it is not fixed because it is nearby.

Why this is strict: in November 2026 the first outside review of 1.5.7 reproduced a wrong saved time that needed Google to change an event's kind, a poll to store it, and a drag from a tab still showing the old version, all within minutes. It was fixed because it was reproduced and in range. That fix caused the second review's regression, and the third review's findings came from the rule written to repair it: two extra review rounds for a sequence the owner, on first hearing it, called extremely unlikely. The plan for that release already said such findings are written down and not built; the rule was applied to the workers' findings and not to the reviewer's.

## Before a push

Every gate completes before any push to `main`: the unit suites, the database suite, both modes of the series harness at full case counts, and the interleaving mode (see [CONTRIBUTING.md](CONTRIBUTING.md) and [tools/edit-harness/README.md](tools/edit-harness/README.md)). `tools/gates.sh full` is that set, run once on the final tree. A gate that has not finished is a gate that has not passed. The one exception is a commit that changes only documentation, which needs no gate ([CONTRIBUTING.md](CONTRIBUTING.md), "Gates: Three Levels", which also says what counts).

The full set is for the push, not for every step. While working, run `tools/gates.sh quick` with the test files near the change; after a merge, `tools/gates.sh merge`. A worker does not run seeds or the mutation list, and does not chase `main`: it hands back on the commit it was given, and the merge is gated on `main` once. Do not add a seed, a mode or a configuration to the full set because a review round used it; a recorded seed is a regression test and one is enough, and breadth belongs in background runs that block nothing ([CONTRIBUTING.md](CONTRIBUTING.md), "Gates: Three Levels").

## Privacy

Nothing in this repository describes a real person's data. Tests, fixtures, docs, examples, commit messages and issues use invented calendars, events, people, places and hosts. Describe behaviour in general terms. Values a deployment needs live in untracked config, never in tracked files.
