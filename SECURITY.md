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
- **2026-09-17 to 2026-09-22**: every finding from both rounds fixed, deployed and verified on the reference install; each fix commit names the finding it closes (BC-01 through BC-21). Issue #24 records the disposition of each. Three client-rendering verification tasks (how mobile calendar apps, ICS subscribers and mail clients render descriptions) moved to issue #58 as evidence to gather, not as known vulnerabilities. 0.9.7 made them moot by fixing the cause instead: descriptions from feeds and Google, stored as they arrived, now leave Better-Cal through the same HTML allowlist the app displays them with (plain formatting and http(s) links only), with plain text that holds no markup, on every export path (outbound feeds, CalDAV, files). 0.9.8 then made storage clean too: every way an event arrives (the app, the API, email, plugins, CalDAV, feeds, Google sync, the Takeout import) stores only allowlisted HTML, and a migration cleaned what was already stored. Display and export still clean as a second layer.

- **2026-09-23**: a Claude Security scan of the whole repository at revision `14f98fe` (high effort, three-verifier panel on every candidate): 29 findings, 9 Medium and 20 Low, none High or Critical. All were fixed in releases 0.1.1 to 0.1.5, deployed and verified on the reference install the same day. Three adversarial reviews of those fixes followed; the gaps and regressions they found were fixed in 0.1.6 to 0.1.8. Each Medium finding was published as its own GitHub security advisory and the Low ones as one combined advisory; [Past advisories](#past-advisories) below lists them with the affected and patched versions. **0.2.0 is the first release with the whole pass in it.**

- **2026-10-06**: a full Codex Security scan of the repository at revision `3cdca5a` (released in 0.9.13): 77 findings, 1 High, 46 Medium and 30 Low. They were fixed in phases through release 0.9.42, each fix with regression tests and an independent review of the patch. One Medium was set aside after testing showed the reported path was already bounded by the recurrence library's own limit, and one Low was accepted rather than fixed. The working record is `docs/security-remediation-checklist-2026-10-06.md`; the checks still to run against a deployed install are tracked in [issue #81](https://github.com/Oshyan/better-cal/issues/81). This was not the final pre-1.0 scan: that one runs on the 1.0 release candidate.

Known residuals, accepted and documented rather than fixed:

- The overall sign-in brake can still be triggered by an attacker controlling 10 or more addresses (cheap with IPv6). While it is on, password sign-ins are refused from addresses that have not signed in during the last 30 days, unless the browser has signed in here before (it carries a device cookie, since 0.2.3). CalDAV devices using API tokens are unaffected. A brand-new device has to wait for the attack to subside; a one-time code to let it in is planned in issue #59.
- Prompt filters and ranking judge events in batches of 25, so one event's text can in principle sway the model's verdicts on the others in its batch. The text is sent as separate, marked data.
- The project's own dev install runs in `DEV_ISOLATION_MODE=shared`: it uses the same Unix, database and PHP accounts as production, so code deployed to dev could read production's files and database. This is accepted because dev only ever runs the maintainer's own code, its port is closed to the outside, and each dev clone is still stripped of production's sessions, tokens, push devices, feeds and Google connections. `strict` stays the default and the recommendation for anyone running a dev copy alongside production (see [docs/install.md](docs/install.md)).
- When the deploy script connects as root, it leaves `.env` root-owned, but the app user owns the directory it sits in, so this is not a hard boundary. Deploying as a non-root user with sudo skips that step.

The latest full scan covers the code as of release 0.9.13 (2026-10-06), and its fixes run through 0.9.42. Changes since then have had tests, including regression tests for every fixed finding, and review as they were made, but not yet a full scan; a final scan of the 1.0 release candidate is planned (issue #81). Anything you find is worth telling us about.

### Past advisories

The 2026-09-23 advisories belonged to the repository's earlier history, which was rewritten on 2026-10-05, so they are kept here as the record; later ones link to their GitHub advisory.

| Severity | Advisory | Affected | Fixed in |
|---|---|---|---|
| Medium | Any successful sign-in reset the source's failed-password count, allowing unlimited guessing | 0.1.0 and earlier | 0.1.1 |
| Medium | An absurd repeat-rule interval from a subscribed feed made every events request fail | 0.1.0 and earlier | 0.1.1 |
| Medium | Dev-instance clone step of the deploy script: the web app's user could reach the database root account, the root password appeared on a command line, and revoked credentials carried over into the dev copy | 0.1.1 and earlier | 0.1.2 |
| Medium | Push subscriptions outlived a password reset and API-token revocation | 0.1.5 and earlier | 0.1.6 |
| Medium | A leaked token or stolen session could create a public feed address that survived revocation | 0.1.6 and earlier | 0.1.7 |
| Medium | A "/" in an external iCalendar UID broke CalDAV object paths | 0.1.6 and earlier | 0.1.7 |
| Medium | A quadratic regular expression over mail HTML from outside stalled the mail worker | 0.1.6 and earlier | 0.1.7 |
| Medium | Subscribed ICS feeds were parsed without a limit on events or bytes | 0.1.7 and earlier | 0.1.8 |
| Low | The 20 Low findings of the 2026-09-23 scan, combined (timing, throttle races, prompt-injection surfaces, ReDoS, an SSRF deny-list gap and others) | 0.1.7 and earlier | 0.1.8 |
| High | [Command-line scripts could be run from the web where the app folder is web-reachable](https://github.com/Oshyan/better-cal/security/advisories/GHSA-m2mq-39mq-vqf6) (published 2026-10-15) | 0.9.81 and earlier | 0.9.82 |

## Releases and updates

How code reaches an install, and what has to be true before it does.

**What the app does on its own:** once a day it checks GitHub for a newer release and tells you, with a link to its notes. It never downloads or installs code.

**What `server/bin/update.php` checks before installing anything** (it runs only when you run it):

1. The release is a published, non-prerelease, **immutable** GitHub release of this repository, so its tag and files can't be swapped after publishing.
2. Its manifest matches GitHub's SHA-256 digest and the rules the update notice applies (version, priority, a secure-version floor that never moves backwards).
3. **Its signature verifies.** Each release carries `bettercal-release.sig`, a statement of its version, commit and the SHA-256 of its zip and manifest, signed by a key in `server/config/release-keys.php`. The signed hashes must match the files being installed, and in a git checkout the tag must point at the signed commit.
4. The downloaded zip matches GitHub's SHA-256 digest, and it unpacks only into its own folder: no paths that climb out, no symbolic links, bounded in size.

**The signing keys never touch GitHub.** There are two, and either one can sign: a key file on the maintainer's machine (with a copy in a password manager) and a FIDO2 hardware security key that needs its PIN and a touch. Releases are signed on the maintainer's machine after GitHub builds the zip. So taking over the GitHub account, or the build, isn't enough to put code on an install: it also takes one of those keys. Keys can be added or dropped in a release signed by a key already trusted, which is how a lost or exposed key is replaced ([docs/releasing.md](docs/releasing.md#signing)).

**During an update:** the site shows "updating" and the worker pauses (it waits for a running job to finish first); the database is backed up first, by PHP itself; the previous version stays whole beside the new one; and `update.php --rollback` puts both the code and the database back. Backups go in a folder beside the app, readable by its account only, with names that can't be guessed. That folder and the previous version's get a deny-all `.htaccess` in case the app sits inside a web root, and the updater warns when it seems to.

**Its own address:** Better-Cal answers only at the host in `BETTERCAL_BASE_URL` and redirects or refuses any other, so files reachable as a folder of another site (`example.com/cal/`, common where hosts store subdomains inside the main web root) can't put it on that site's origin. It needs a domain or subdomain of its own, and a separate hosting user where it shares an account with other sites ([docs/install.md](docs/install.md#choosing-where-to-put-it)). Where a host only lets a subdomain point inside the main site's web root, a `.htaccess` at the top of the app folder refuses every request for anything in it except `server/public` (Apache and LiteSpeed; with Nginx the folder must stay out of any web root).

**First install:** `server/bin/install.php` writes `.env` readable by its owner only, with a freshly generated session secret, and never replaces an existing one.

**Setup and database updates in the browser** (for hosting without a command line): a fresh install with no `.env` shows a setup page on every path, and an install whose new files have database changes waiting shows a database update page. Both run before anyone can sign in, so both first ask for a one-time code written to `better-cal-code.txt` in the app folder, outside the web root: only someone who can read the server's files gets past the first screen, which keeps a stranger who finds a new install first from setting it up for themselves. The code is 60 bits, expires after an hour, is replaced after 5 wrong tries and works once; it starts a session in an HttpOnly, SameSite=Strict cookie, and every form carries a token derived from it. Nothing about the server is shown before the code is entered. The pages send a strict Content-Security-Policy and can't be framed. Setup exists only while there is no `.env`: once it writes one, that code path is never reached again, and it refuses a database that already holds an account. The database update backs up first and shows "updating" to everyone else while it runs. While changes wait, the API and CalDAV refuse with 503 rather than run against a half-updated schema.

**Third-party browser code:** the libraries under `web/vendor/` (Preact, htm, Squire, DOMPurify, Leaflet) are the published npm releases, some minified, changed only where [web/vendor/README.md](web/vendor/README.md) says (two one-line edits to Preact's files). Each is pinned by SHA-256 in `web/vendor/manifest.json`, every deploy checks the tree against those pins, and `node scripts/vendor.mjs --check-upstream` re-downloads each release and confirms the committed file matches it. The PHP libraries are pinned in `server/composer.lock` and checked against the Packagist advisory database on every push and deploy.

**Limits, stated plainly:**

- The update notice in the app is not signature-checked, only verified against GitHub. Someone controlling the GitHub account could make installs *announce* a release (even a "security" one) that doesn't exist or isn't signed; `update.php` would still refuse to install it.
- Updating from 0.9.74 to 0.9.75 used the 0.9.74 updater, which predates signatures. From 0.9.75 on, every update is checked.
- Updating by hand (downloading the zip yourself, uploading it through a file manager, or `git checkout`) skips these checks unless you verify the signature yourself; [docs/releasing.md](docs/releasing.md#signing) shows the command.
- On shared hosting, PHP can usually write to the app's own files, so anyone who can run PHP as that account can change the code. That's inherent in this kind of hosting, and why an update needs nothing more than that account.
- The deny-all `.htaccess` files protect backups only under Apache. With Nginx, keep the app outside the web root, as the install guide says.

## Design trade-offs

These are deliberate choices, not oversights. Each makes daily use easier and costs something if a device you use is lost or stolen.

- **Staying signed in.** A browser session lasts 180 days. That suits a calendar you open many times a day. The cost is that anyone holding an unlocked device that is signed in can use your calendar without the password until the session ends or is revoked.
- **Remembered browsers (since 0.2.3).** A browser that has signed in keeps a device cookie for a year, even after you sign out. The cookie never signs anyone in by itself. It only lets a sign-in with the right password through the overall sign-in brake while a distributed attack is going on (issue #59). Someone with the device but not the password gains at most 3 password guesses per 15 minutes while the brake is on. So the remembered browser adds very little to what a lost device already exposes. The open session is the real risk.
- **Never locking the account.** Wrong passwords slow guessing down per address and overall, but the account itself is never locked, so nobody can lock you out of your own calendar by guessing badly on purpose. The cost is that a weak password is only slowed down, not protected. Use a long, unique one.
- **CalDAV apps hold a credential.** A phone or desktop calendar app syncing over CalDAV stores whatever you gave it. Give it an API token rather than your password, so a lost device can be cut off by revoking one token instead of changing the password everywhere.

### If a device is lost or stolen

1. From a device you still have, open Settings, Account and choose **Sign out everywhere else** (since 0.2.4). This signs out every other browser, forgets every other remembered browser, stops push reminders to every other device, gives every public calendar feed created while signed in a new URL, and sends reminder email back to the account address. The browser you use stays signed in. External calendars using an old feed URL need the replacement shown in Better-Cal.
2. On the same page, revoke any API key the lost device held.
3. If you think the password itself was seen, or you have no signed-in device left, reset it on the server: `php server/bin/seed.php --email=you@example.com`. It asks for the new password, then signs out every browser including yours, stops push reminders to every device and forgets every remembered browser. On its own it does not give feeds new addresses or reset the reminder email the way step 1 does. Adding `--revoke-tokens` does those and more: it revokes every API key (and pauses the calendar subscriptions those keys created), gives every outbound feed a new address, sends reminder emails back to the account's own address, pauses every connected Google account and asks Google to revoke its access, and stops unfinished Google moves. Reconnect each Google account under Settings, Connections afterward; cached events stay visible while it is paused.

## Scope notes

- Better-Cal is single-user. There is one login; other people reach it through CalDAV, shared feeds and invitations. Problems that require being that one logged-in user are still worth reporting if they cross a boundary the app is supposed to keep (a feed reaching an internal address, a plugin escaping its calendar, an emailed invitation changing something without a decision).
- Outbound fetches (feeds, geocoding, Google, plugins) go through one HTTP client that refuses private, loopback, link-local and cloud-metadata addresses, checks and pins the address again after every redirect, and ignores proxy settings. Web push is sent separately, only to known browser push services (an operator can add more with `BETTERCAL_PUSH_EXTRA_HOSTS`), over https on port 443 with redirects off. A way around either is a finding.
- Secrets live in `.env` and in the database sealed with libsodium; nothing in this repository should ever hold one. If you see one, that is a finding too.
- `server/bin/update.php` must never install a release that isn't signed by a trusted key, or write outside the app's own folders. A way to make it do either is a finding.
- The setup and database update pages must do nothing for someone without the code from `better-cal-code.txt`, and setup must never run once `.env` exists. A way around either is a finding.
