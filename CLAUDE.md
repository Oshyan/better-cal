@AGENTS.md

The rules above apply to every change. In particular: a change to a stored form is a format version bump, never a migration that rewrites old rows, and no transforming migration is written without the owner's explicit choice. Check [docs/design/decisions.md](docs/design/decisions.md) before changing how anything is stored or compared, and add an entry there in the same commit as the change.
