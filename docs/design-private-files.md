# Design: Private Files That Stay Private on Any Host

Status: built in 0.9.83, 2026-10-15, after a review of the first draft. Tracked in [#142](https://github.com/Oshyan/better-cal/issues/142).

## The problem

Better-Cal keeps a few files that must never be downloadable: the settings file (database password, session secret, push and mail keys, the Google client secret), database backups, the one-time code that unlocks the setup and database update pages, and logs. They live in or beside the `better-cal` folder, and the docs ask people to keep that folder out of the web root, serving only `server/public`.

That isn't always possible. Some hosts (Hostinger, and any cPanel server with its default "restrict document roots to public_html" setting) only let a subdomain point inside the main site's `public_html`. The whole folder then sits where the main domain serves files, and `example.com/better-cal/.env` would hand out the database password. Bots scan for exactly this: CISA advisory AA24-016A and Palo Alto Networks' Unit 42 describe mass scanning for `/.env`, one campaign harvesting such files from 110,000 domains.

0.9.81 added a `.htaccess` at the top of the folder that refuses every request. It works on Apache and LiteSpeed, but Nginx ignores it (Nginx-only hosts, Hostinger's Agency plans, Plesk's "serve static files directly by nginx"), OpenLiteSpeed reads only its rewrite rules, and a host can switch overrides off. 0.9.82 made every PHP file but the two front controllers refuse to run from the web.

## Goals

- Secrets can't be downloaded whatever the web server does with `.htaccess`, as long as it runs PHP files as PHP, which any host running Better-Cal must.
- Backups are useless to whoever downloads them, on any server, as long as the settings file (which holds their key) isn't downloadable too.
- A non-technical person learns, in plain words, whether their install is safe, without worrying messages when it is.
- Existing installs keep working through the update, convert without anyone doing anything by hand, and can still roll back.

## Not goals (1.x)

- Moving the private part of the app above the web root automatically. It is the structural fix, but it reorganises how `server/public` finds the rest of the app; with this design it stops being urgent.
- Restoring an encrypted backup from the browser, except right after a failed database update on that page. Otherwise restoring is `update.php --rollback` or `backup.php --decrypt`.

## Design

### 1. The settings file is `.env.php`

The same `KEY=VALUE` lines, after a guard line:

```
<?php __halt_compiler(); // Better-Cal settings. Keep this first line: it stops this file being shown on the web.
BETTERCAL_BASE_URL=https://cal.example.com
```

- Requested over the web, the server runs it: `__halt_compiler()` stops PHP at once and the response is empty. Nothing after it is ever parsed, so a value containing `<?` can't break it (the reason `<?php exit; ?>` was rejected).
- `Support\SettingsFile` reads it as text, never with `include`. A byte-order mark, CRLF and blank lines before the guard are fine; UTF-16 or anything else in front is refused with a plain message (CLI) or a bare 503 with the details in the error log (web). A guard line that was simply deleted is put back when the rest is plainly settings: refusing to start doesn't make an already exposed file safer.
- `.env.php` wins over `.env`; real environment variables win over both.
- **Conversion** (`SettingsFile::convert`): writes `.env.php` through a temporary file that also ends in `.php`, keeps the mode (and owner and group, when run as root), checks it reads back to the same values, then deletes `.env`. Both present with equal values: `.env` is deleted. Both present and different: nothing changes, and the self-check says so. It only touches files owned by the account running it.
- **Who converts:** `update.php --finish`, the database update page, `install.php` when run again, and the worker each minute when there's anything to do (`Infra\PrivateFiles`), each for files its account owns. `php server/bin/settings.php --convert` does it by hand, which is how a root-owned `.env`  converts.
- **Writers:** `install.php`, the setup page and `Setup::writeEnv` write `.env.php` and refuse to write beside an existing `.env`. `.env.example` starts with the guard line. The deploy scripts (`deploy.sh`, `deploy-backup.sh`, `deploy-dev.sh`, `push-settings.sh` with `merge-env.py`, `sanitize-dev-clone.php`) read `.env.php` when present and refuse both at once; the guard line holds no `=`, so their line parsers read both files alike.

### 2. Every other secret-bearing file is guarded too

- The access code and its session state share `better-cal-code.php`: a guard line, the readable code and instructions, then one JSON state line last. Malformed or inconsistent edited files, including files saved with Windows line endings, are treated as missing state and replaced with a fresh code. The first successful entry consumes the code and starts one session. A separate `.runtime-cache/access-code-lock.php` is never replaced or removed during the flow. Old split-file state is discarded on the next visit. Deleting the code file resets access if the browser loses its session. Unsupported locking is logged, not shown on the page; atomic writes still work, but concurrent requests may race on hosts that cannot enforce locks.
- The update record is `update-state.php` (guard, then JSON); `update-state.json`, written by older updaters, is still read, so an update from 0.9.82 is finished by the new code, and removed on the next write.
- The self-check's result is `.runtime-cache/private-files.php`.
- **PHP's error log** (`Support\ErrorLog`): where the host's `error_log` setting is a bare name or points inside the app folder (shared hosts often write `error_log` beside the running script, which for Better-Cal is `server/public`), errors go to `.runtime-cache/php-errors.php`, guarded, trimmed past 2 MB. A log the host keeps elsewhere is left alone.
- No `.bak` or `~` copies of guarded files are ever made.

### 3. Backups are encrypted

- `DbBackup` streams gzip into `Infra\BackupCipher`: libsodium's secretstream (XChaCha20-Poly1305) in 64 KB chunks, under a `BCBK1` preface with an 8-byte key id, authenticated with every chunk; the last chunk is tagged final and nothing may follow it. Files end in `.bcbackup` and carry 128 random bits in their names.
- **The key is derived from `BETTERCAL_SESSION_SECRET`** (libsodium KDF, context `bcbackup`). A restore needs the session secret anyway (feed credentials and Google tokens are sealed with it), so there's no new key to keep or lose. A restore with other settings says so plainly.
- `restore()` opens the file once and reads the whole of it, authenticating it, before it changes anything; the SQL pass reads the same handle. It still reads older plain `.sql.gz` backups once confirmed (`--allow-plain`, or the restore page's second click). Without POSIX it refuses group-writable parent folders.
- Older plain backups beside the app are encrypted in place by the conversion above; the update record follows the new name.
- `php server/bin/backup.php --decrypt FILE OUT` writes a plain `.sql.gz` for phpMyAdmin or `mysql`.
- After a failed database update, the database update page offers "Restore the database as it was" from the backup it just made.

### 4. Copies beside the app

- Settings in copies `update.php` made (`<name>-<version>`, `<name>-<version>-rolled-back`) are converted when their code reads `.env.php`, or when the copy is the update's previous version, which a rollback gives its `.env` back. A copy someone made by hand (`better-cal-old`, a staging copy) runs as it is and is never touched; the self-check notes it when it still keeps `.env`.
- Since 0.9.86, the updater keeps its newest two previous versions and database backups, and prunes older updater-owned copies. Copies made by hand are not removed. This supersedes the earlier design that kept every copy.
- **Rollback to a version before 0.9.83**, which reads only `.env`: `update.php --rollback` writes `.env` from `.env.php` into that version's folder (and removes the `.env.php` it can't read), and says that version doesn't guard its settings.

### 5. The worker's output

The documented cron line discards output: `* * * * * php /path/better-cal/server/bin/worker.php >/dev/null 2>&1`. The worker turns `display_errors` off and sends PHP errors and job failures to the private error log; failing jobs show in Settings, System as before. An install whose cron line still writes `worker.log` into the folder gets a note in the self-check with the new line.

### 6. The `.htaccess` stays

It protects in the one case the guard doesn't: a server whose PHP handling breaks (PHP files downloaded as text) while still honouring `.htaccess`.

### 7. A self-check that says plainly whether the install is safe

`Infra\PrivateFilesCheck`.

**Where it looks.** The part of the app folder's path after the last folder hosts use as a web root (`public_html`, `htdocs`, `httpdocs`, `www`, `html`, `web`, `public`, or a folder named for a domain, as DreamHost does). No such folder: it asks for the folder at the main domain's top level (`/better-cal/`), and the best it can then say is "Probably safe", since a web root can be named anything; an earlier draft called that case "Safe" on the path alone. The hosts: the main domain of `BETTERCAL_BASE_URL`'s host and its `www.` form (with a short list of two-level suffixes such as `co.uk`), plus a domain the path is named for. Never the install's own address, an IP, a bare suffix, or a name shared with strangers (dynamic DNS, tunnels, hosting platforms such as `ts.net`, `duckdns.org`, `github.io`), where the "main domain" belongs to someone else.

**How.** It writes `bc-probe-<random>.php` into the app folder (the guard line, then a random token), and for each host fetches it, a made-up `bc-missing-<random>.php` at the root as a control, and the settings file itself (`.env.php`, and `.env` while it exists). Over https, then http for hosts that don't answer; one redirect is followed when it keeps the path and stays on the same main domain. 3-second connect and 6-second total timeouts, 64 KB of each body. The probe is deleted afterwards, and leftovers older than two minutes before each run. These requests go to the install's own main domain only, so they don't use the outbound client's private-address rule (a home server's main domain may resolve to a private address).

**What it concludes,** per host, the worst across hosts winning:

- The settings file comes back with `BETTERCAL_` in it: exposed.
- The probe's token comes back but the settings file doesn't (a server that refuses dotfiles, or can't read owner-only files): PHP files are served as text there, but the settings are out of reach. "Probably safe, but fix this."
- An empty 200, unless the control is an empty 200 too: reachable but guarded. Safe.
- A 403 while the control is a 404: refused. Safe.
- No answer from any host: not checked.
- Otherwise: probably safe.

**When.** At the end of setup and of `install.php`, after `update.php` and the database update page, and from "Check now" (at most once a minute). Not on a timer.

**What people see.** One row in Settings, System, **Private files**, with the result, notes and "Check now". Setup, the installers and the updaters show the result only when it isn't plainly Safe.

- "**Safe.** Your Better-Cal folder sits inside example.com's public web space, but the server refuses every request for it, so no one can download your settings or backups."
- "**Safe.** example.com can reach the Better-Cal folder, but your settings file shows as a blank page there and your backups are encrypted."
- "**Probably safe.** Your settings file can't be shown on the web and your backups are encrypted. Better-Cal couldn't find which address, if any, serves its folder. If your hosting account's main domain isn't example.com, open that domain/better-cal/.env.php: a blank page, Forbidden or Not Found is good."
- "**Fix this now: your settings can be downloaded.** example.com/better-cal/.env.php shows your database password to anyone, because this server isn't running PHP for that folder. Move the better-cal folder out of public_html, or ask your host to turn PHP on for it. Once it's fixed, change your database password, since it may already have been copied."
- "**Not checked.** Your host doesn't let Better-Cal fetch pages from its own websites, which is common and not a problem in itself. To check yourself, open example.com/better-cal/.env.php: a blank page, Forbidden or Not Found means your settings are safe; if you see lines starting with BETTERCAL_, see (the install guide)."

Notes, where the folder isn't out of reach: an old `worker.log`, `error_log` files PHP wrote into the folder (`server/public/error_log` always, since the app's own address serves it), settings still in `.env` or two differing settings files, older copies beside the app, and backups not yet encrypted.

**Honest limits.** It checks the main domain at the place it would serve the folder. An unrelated domain on the same account pointing at the folder isn't covered.

## Edge cases

- **Exports are a separate, short-lived exception to guarded private files.** Single-calendar ICS downloads stream directly. Account ZIP exports use native `ZipArchive::addFile()` with private ICS spools in `.runtime-cache/exports`, mode 0700, under umask 0077, with random 128-bit names. No encryption or new library is added. One nonblocking lock covers building, sending and cleanup. Finally and shutdown handlers remove files, and the next lock holder cleans abandoned files. Host limits and disk capacity still apply; there is no event cap. Keep the cache out of public web space. A same-account web server can read these files if a misconfigured site exposes their addresses; the web probe does not promise otherwise.
- **Missing backup key.** New backups refuse an empty session secret before opening an output file. Settings, System warns immediately and offers a generated line to paste into the settings file; the database update page does too. No automatic settings write occurs. Recovery still accepts older plain backups. A lost original secret must be recovered to read older encrypted backups or service credentials.
- **Backup path safety.** Before writes, permission changes, encryption or pruning, backup paths and entries must be real and owned by the running account, without symlinks or file hard links. Root does not convert backups through an app-owned tree; run that conversion as the storage owner. Restore holds the update and worker locks, and a failure keeps maintenance on with an explicit retry form.

Export and backup checks allow normal group-writable home and app folders when their group matches the running account's effective group. Hosts without the optional POSIX functions may use group-writable parents too. This relies on the host keeping account groups private; the app does not check group membership lists. World-writable parents need the sticky bit, and root must not operate through another account's folders. The storage directories themselves remain owner-only.
- **open_basedir.** Backups go beside the app only when PHP may access and write that folder; otherwise they go inside it. Private-path checks start at the host's allowed base instead of trying to inspect prohibited ancestors. PHP enforces the boundary on resolved link targets. POSIX is optional: an exclusive empty file can identify the writing account when the system temporary folder is unavailable.
- **File managers** hide dotfiles; the no-SSH update steps say to show hidden files to copy `.env.php`.
- **CDNs in front** (Hostinger's, Cloudflare): the probe goes through the same front door a visitor uses. Probe names are random each time, so a cached answer can't mislead.
- **Malware scanners** on shared hosts (Imunify360, Monarx) may flag files containing `__halt_compiler`. To be checked on Hostinger before 1.0.
- **A host serving PHP as another user** (old shared mod_php setups) needs the settings world-readable, exposing them to other accounts on the server. Unchanged by this design, and documented.

## Testing

- Unit tests (`server/tests/run.php`): the guarded reader (guard present, deleted, mangled, BOM, CRLF, UTF-16, a value containing `<?php`), conversion (mode, equal and differing pairs, no leftovers), writers, the access code's files, backup encryption (chunks, other settings, cut-off, a changed byte, data after the end), encrypting older backups and reading both kinds, the update record, sibling conversion and pruning, the error-log rule, and the self-check (hosts, paths, classification, and whole runs against a pretend server that serves raw files, runs PHP, refuses, isn't mapped, doesn't answer, or redirects).
- Docker, the app inside a `public_html`: Apache honouring `.htaccess`, Apache with `AllowOverride None`, Nginx running PHP for the folder, Nginx serving it raw (expect Exposed); the update from 0.9.82 with a plain `.env`, converting on the way, and a rollback to 0.9.82.
- A non-shared-host fixture with the app outside the web root and operator-owned settings, with a backup taken first.
- A shared-host fixture, including whether its malware scanner accepts guarded PHP files.

## Sources

CISA AA24-016A (https://www.cisa.gov/news-events/cybersecurity-advisories/aa24-016a), Unit 42 on `.env` extortion (https://unit42.paloaltonetworks.com/large-scale-cloud-extortion-operation/), WPScan on configuration backups (https://wpscan.com/blog/wordpress-configuration-file-backups/), Matomo's guarded `config.ini.php` and its private-directories check (https://github.com/matomo-org/matomo/issues/17559), Plesk's Apache and nginx settings (https://docs.plesk.com/en-US/obsidian/customer-guide/websites-and-domains/hosting-settings/web-server-settings/apache-and-nginx-settings.72320/), Hostinger Agency's `.htaccess` (https://www.hostinger.com/support/how-to-enable-htaccess-on-agency-plans/), cPanel's document-root restriction (https://support.cpanel.net/hc/en-us/articles/360050710393), OpenLiteSpeed access control (https://docs.openlitespeed.org/security/access/), PHP's `__halt_compiler` (https://www.php.net/manual/en/function.halt-compiler.php).
