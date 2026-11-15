@AGENTS.md

The rules above apply to every change. In particular: a change to a stored form is a format version bump, never a migration that rewrites old rows, and no transforming migration is written without the owner's explicit choice. Check [docs/design/decisions.md](docs/design/decisions.md) before changing how anything is stored or compared, and add an entry there in the same commit as the change.

Before fixing anything that was found (by an outside review, a worker, a harness seed, an issue or your own reading), sort the findings by how likely their cause is to occur in normal use, and bring the unlikely ones to the owner as "file or fix?" before any work starts ([AGENTS.md](AGENTS.md), "Sort what was found by likelihood, before fixing any of it"). A severity label is not a likelihood.
