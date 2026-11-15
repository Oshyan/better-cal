# Code review of 2026-11-14

Five read-only studies of the code as it stood at 1.5.6, made to go looking for improvements nobody had asked about. They are working papers: kept here so the issues that came of them can link to the full reasoning, and to be removed once their findings are dealt with. The tracking issue is [#286](https://github.com/Oshyan/better-cal/issues/286).

- [Server performance](perf-server.md)
- [Client performance](perf-client.md)
- [Resource use](resource-use.md), with a table of everything that grows without bound and what limits it
- [Special cases to rules](special-cases.md), with the areas looked at and judged clean
- [Maintainability](maintainability.md)

How to read them:

- Each was written by a separate agent that changed nothing and ran no test suite, benchmark or database query, because a release gate was running at the time. Counts (queries, rows, files) come from reading the code. Times are either estimates or timings of small scripts on a development machine. Nothing was measured on a real install.
- File and line references are to commit `3702b514` and will drift.
- The top claims of each were checked against the code before any issue was filed. The rest are the agent's reading, and a finding marked "candidate" was not confirmed.
- Paths under `workfolder/` are working files outside the repository.
