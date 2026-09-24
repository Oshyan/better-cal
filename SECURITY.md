# Security

Better-Cal holds a person's calendar, their contacts, and the credentials that reach their Google account and mail. Reports are taken seriously and answered.

## Reporting a vulnerability

Please do not open a public issue for a security problem.

- Preferred: GitHub private vulnerability reporting on this repository (the "Report a vulnerability" button under the Security tab). It stays private until a fix is out.
- Or email coding@oshyan.com.

Include what you found, how to reproduce it, and what you think it allows. You will get a reply within a few days, an honest assessment, and credit in the fix commit if you want it. There is no bug bounty.

## What has been reviewed

- **2026-08-04**: a Codex Security discovery pass over the whole repository, independently validated by a manual review (`docs/security-review-2026-08-04.md`). No Critical or High; 15 Medium and 5 Low.
- **2026-09-12**: a full Codex Security scan of the repository, reconciled against the earlier findings and recorded in [issue #24](https://github.com/Oshyan/better-cal/issues/24): 17 active findings, 9 Medium and 8 Low, none High or Critical, one of them new (BC-21).
- **2026-09-17 to 2026-09-22**: every finding from both rounds fixed, deployed and verified on the reference install; each fix commit names the finding it closes (BC-01 through BC-21). Issue #24 records the disposition of each. Three client-rendering verification tasks (how mobile calendar apps, ICS subscribers and mail clients render descriptions) moved to issue #58 as evidence to gather, not as known vulnerabilities.

- **2026-09-23**: a Claude Security scan of the whole repository at revision `fa580be` (high effort, three-verifier panel on every candidate): 29 findings, 9 Medium and 20 Low, none High or Critical. All were fixed in releases 0.1.1 to 0.1.5, deployed and verified on the reference install the same day. Three adversarial reviews of those fixes followed; the gaps and regressions they found were fixed in 0.1.6 to 0.1.8. Each finding is tracked as a GitHub security advisory with its affected and patched versions. **0.2.0 is the first release with the whole pass in it.**

Known residuals, accepted and documented rather than fixed:

- The overall sign-in brake can still be triggered by an attacker controlling 10 or more addresses (cheap with IPv6). While it is on, password sign-ins are refused from addresses that have not signed in during the last 30 days, unless the browser has signed in here before (it carries a device cookie, since 0.2.3). CalDAV devices using API tokens are unaffected. A brand-new device has to wait for the attack to subside; a one-time code to let it in is planned in issue #59.
- Prompt filters and ranking judge events in batches of 25, so one event's text can in principle sway the model's verdicts on the others in its batch. The text is sent as separate, marked data.
- The deploy script leaves `.env` root-owned, but the app user owns the directory it sits in, so this is not a hard boundary.

Anything you find after that is new, and worth telling us about.

## Scope notes

- Better-Cal is single-user. There is one login; other people reach it through CalDAV, shared feeds and invitations. Problems that require being that one logged-in user are still worth reporting if they cross a boundary the app is supposed to keep (a feed reaching an internal address, a plugin escaping its calendar, an emailed invitation changing something without a decision).
- Outbound fetches (feeds, geocoding, web push, Google) go through one HTTP client that refuses private, loopback, link-local and cloud-metadata addresses. A way around it is a finding.
- Secrets live in `.env` and in the database sealed with libsodium; nothing in this repository should ever hold one. If you see one, that is a finding too.
