# Security

Better-Cal holds a person's calendar, their contacts, and the credentials that reach their Google account and mail. Reports are taken seriously and answered.

## Reporting a vulnerability

Please do not open a public issue for a security problem.

- Preferred: GitHub private vulnerability reporting on this repository (the "Report a vulnerability" button under the Security tab). It stays private until a fix is out.
- Or email coding@oshyan.com.

Include what you found, how to reproduce it, and what you think it allows. You will get a reply within a few days, an honest assessment, and credit in the fix commit if you want it. There is no bug bounty.

## What has been reviewed

A full Codex Security scan was run against revision `ea1ef19` and its candidates were independently validated by a manual review on 2026-08-04 (`docs/security-review-2026-08-04.md`). It found no Critical or High issues, 15 Medium and 5 Low. Every one of the twenty was fixed between 2026-08-05 and 2026-09-22; the fix commits name the finding they close (BC-01 through BC-21). The two leads deferred pending deployment evidence are recorded in the same document.

Anything you find after that is new, and worth telling us about.

## Scope notes

- Better-Cal is single-user. There is one login; other people reach it through CalDAV, shared feeds and invitations. Problems that require being that one logged-in user are still worth reporting if they cross a boundary the app is supposed to keep (a feed reaching an internal address, a plugin escaping its calendar, an emailed invitation changing something without a decision).
- Outbound fetches (feeds, geocoding, web push, Google) go through one HTTP client that refuses private, loopback, link-local and cloud-metadata addresses. A way around it is a finding.
- Secrets live in `.env` and in the database sealed with libsodium; nothing in this repository should ever hold one. If you see one, that is a finding too.
