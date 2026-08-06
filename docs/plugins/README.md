# Plugin system planning

Working documents for GH [#4](https://github.com/Oshyan/better-cal/issues/4) (simple plug-in system). Produced 2026-08-06 from the full issue thread, including the extended idea survey and the closing directive to weigh every capability against performance, data model, and complexity cost before committing to it.

Read in order:

1. [idea-evaluation.md](idea-evaluation.md): every plugin idea from the thread, mapped against what the codebase already proves versus what each idea would force the plugin system to grow. Ends with the capability-by-capability cost review.
2. [idea-ranking.md](idea-ranking.md): the ideas sorted into four buckets (Must Build, Promising, Questionable, Not Now / Don't Build) with a one-line justification each.
3. [prd-v1.md](prd-v1.md): preliminary PRD for the recommended v1 architecture and capability set, with explicit in-scope and out-of-scope lists grounded in the other two documents.

The one-sentence conclusion: build a small worker-centric plugin core by generalizing four seams that already run in production (feed materialization, availability overlays, per-calendar settings, the job queue), prove it with Weather, Tides, and a Calendar Lint audit, and explicitly refuse the solver, booking-write, and arbitrary-UI capability families that would each cost more than the entire core.
