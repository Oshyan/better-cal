# Design: Private Files That Stay Private on Any Host

Status: proposed for 1.0, 2026-10-15; reviewed before implementation. Tracked in [#142](https://github.com/Oshyan/better-cal/issues/142).

## The problem

Better-Cal keeps a few files that must never be downloadable: the settings file (database password, session secret, push and mail keys, the Google client secret), database backups, the one-time code that unlocks the setup and database update pages, and the worker's log. Today they live in or beside the `better-cal` folder, and the docs ask people to keep that folder out of the web root, serving only `server/public`.

That isn't always possible. Some hosts (Hostinger, and any cPanel server with its default "restrict document roots to public_html" setting) only let a subdomain point inside the main site's `public_html`. The whole `better-cal` folder then sits where the main domain serves files, and `example.com/better-cal/.env` would hand out the database password. Bots scan for exactly this: CISA advisory AA24-016A and Palo Alto Networks' Unit 42 describe mass scanning for `/.env`, one campaign harvesting such files from 110,000 domains.

0.9.81 added a `.htaccess` at the top of the folder that refuses every request (`RewriteRule ^ - [F]`). It works on Apache and LiteSpeed, but it is not enough on its own:

- Nginx ignores `.htaccess`. That includes Nginx-only hosts, Hostinger's Agency plans (an Nginx-based server with `.htaccess` off by default), and Plesk's opt-in "serve static files directly by nginx", whose default list has included `txt` and `gz`: the setup code and backups.
- OpenLiteSpeed reads only the rewrite rules, and only after a restart.
- A host can switch `.htaccess` overrides off.

## Goals

- Secrets can't be downloaded whatever the web server does with `.htaccess`, as long as it runs PHP files as PHP, which any host running Better-Cal must.
- Backups are useless to whoever downloads them, on any server.
- A non-technical person learns, in plain words, whether their install is safe, without being shown worrying messages when it is.
- Existing installs keep working through the update, and nothing a person has to do by hand is easy to get wrong.

## Not goals (1.x)

- Moving the private part of the app above the web root automatically, leaving only `server/public` inside it. It is the structural fix, but it reorganises how `server/public` finds the rest of the app. With this design it stops being urgent.
- Restoring an encrypted backup from the browser. In 1.0, restoring is `update.php --rollback` or a command-line decrypt.

## Design

### 1. The settings file becomes `.env.php`

The same `KEY=VALUE` lines, after a guard line:

```
<?php /* Better-Cal settings. Keep this first line: it stops this file being shown on the web. */ __halt_compiler();
BETTERCAL_BASE_URL=https://cal.example.com
...
```

- Requested over the web, the server runs it as PHP: `__halt_compiler()` stops PHP at that point, so the response is empty. This was chosen over `<?php exit; ?>`, which the research found unsafe: a value containing `<?` (a password can) makes PHP fail to parse the file, and with errors displayed the error message prints part of the secret and the server path. Nothing after `__halt_compiler()` is ever parsed.
- Better-Cal reads it as text, never with `include`. The reader strips a UTF-8 byte-order mark, accepts CRLF, and parses the lines after the guard exactly as `.env` lines are parsed today.
- **The guard is checked on every read.** If the file doesn't start with the guard (deleted line, a typo, UTF-16, something in front of it), Better-Cal refuses to start and says so in plain words: "The settings file .env.php has lost its first line, which keeps it from being shown on the web. Better-Cal won't start until it's back: copy the first line from .env.example.php." The CLI scripts print the same.
- **Dotted name.** `.env.php` keeps the leading dot: many Nginx configurations refuse dotfiles, an extra layer. Some file managers hide dotfiles; the no-SSH docs say how to show them, as they already must for `.htaccess`.
- **Compatibility.** If `.env.php` exists it is used; otherwise `.env`, as today. Real environment variables still win over both.
- **Converting an existing `.env`.** `php server/bin/settings.php --convert` writes `.env.php` (owner and mode kept, atomically, through a temporary file that itself ends in `.php`), checks it reads back to the same values, then deletes `.env`. `update.php --finish` and the database update page run the same conversion after an update, so ordinary installs convert on their next update. On the reference server, where `.env` is root-owned by design, the conversion is a deliberate operator step, like provisioning `.env` is today.
- **Writers.** `install.php`, the setup page, `Setup::writeEnv`, `scripts/push-settings.sh` and `merge-env.py`, and `deploy-backup.sh`'s credential read all handle `.env.php`, with the guard. `.env.example` becomes `.env.example.php` (the guard line plus the documented keys). `deploy-lib.sh` already excludes `.env.*` from deploys, so a deploy never touches `.env.php`.

### 2. Every secret-bearing file Better-Cal writes ends in `.php`

- The access code becomes `better-cal-code.php`: the guard line, then the same human-readable text. It reads normally in a file manager; over the web it is an empty page.
- Temporary files for atomic writes of guarded files are named `*.tmp-<random>.php`, then renamed into place.
- The access code's state (`.runtime-cache/access-code.json`) holds only a hash and a session hash, never the code. It moves to `.runtime-cache/access-code.php`, guarded, so nothing under `.runtime-cache` is a readable data file.
- No `.bak` or `~` copies of guarded files are ever made. The research's main finding about WordPress is that configuration leaks come from such copies (`wp-config.php.bak`, editor leftovers), not from the live file.

### 3. Backups are encrypted

- Database backups (`DbBackup`) are written as gzip, then encrypted with libsodium's secretstream (XChaCha20-Poly1305), streamed, under a `BCBK1` header. The key, `BETTERCAL_BACKUP_KEY` (32 random bytes, base64), lives in `.env.php`. The installers generate it; conversion adds it to existing installs.
- Restore (`update.php --rollback`, `DbBackup::restore`) decrypts transparently, and still reads older unencrypted backups. `php server/bin/backup.php --decrypt FILE OUT` gives a plain `.sql.gz` for restoring with phpMyAdmin or another tool.
- Names carry 128 bits of randomness (32 hex characters) instead of 32, as a second barrier.
- **The key is the one new way to lose something:** without it, the backups can't be read. It lives in the same `.env.php` that the updater carries into each new version and keeps in the previous version's folder. The docs say plainly: keep a copy of `.env.php` with any backup you keep elsewhere.
- The previous version's folder kept by `update.php` holds only the guarded `.env.php`. A leftover plain `.env` is deleted there too, when converting.

### 4. The worker keeps its own log, privately

- `worker.php` writes its run summary and PHP errors to `.runtime-cache/worker-log.php` (guarded, trimmed to the last 1,000 lines). It no longer relies on cron output.
- The documented cron line discards output: `* * * * * php /path/better-cal/server/bin/worker.php >/dev/null 2>&1`. `install.php` and the setup page print that form.
- An existing `worker.log` in the app folder is reported by the self-check (below) with how to change the cron line, and deleted once the new log is in use.

### 5. The `.htaccess` stays

It protects in the one case the guard doesn't: a server whose PHP handling breaks (PHP files downloaded as text, as happens after a removed PHP version leaves a stale handler on cPanel) while still honouring `.htaccess`. The two layers fail together only on an Nginx-family server whose PHP handling is also broken, which is rare and conspicuous: the main site breaks too.

### 6. A self-check that says plainly whether the install is safe

**What it checks.** Whether the main domain can reach the `better-cal` folder, and if so, whether it hands anything out.

1. Hosts: the parent domain of the install's own host and its `www.` form (`cal.example.com` checks `example.com` and `www.example.com`). An install at an apex domain has no parent to check and is reported safe on that basis.
2. Paths: for the app folder's absolute path, each tail of up to four segments (`/better-cal/`, `/public_html/better-cal/`, and so on), the ways a main domain could map onto it.
3. Two probe files, written into the app folder under random names and deleted afterwards: `bc-probe-<random>.php`, which prints a random token when run, and `bc-probe-<random>.txt`, holding another token.
4. For each host and path, Better-Cal fetches the PHP probe (through the app's own HTTP client, its usual limits and timeouts, no redirects followed).
   - It returns the token: the main domain serves the folder and runs PHP there. The `.txt` probe at the same place then decides between "refused" (403/404) and "served" (its token comes back).
   - It returns the PHP source (the body contains `bc-probe` source text): PHP isn't running there, and anything guarded would be readable. Exposed.
   - 403 at every candidate: the folder is in the web space, refused by `.htaccess`.
   - 404 everywhere: the main domain doesn't map onto the folder at any of the places it could.
5. If no request completes (outgoing requests blocked, DNS failing), the result is "Not checked".

**When it runs.** At the end of setup and of `install.php`, after `update.php` and the database update page, and on demand from Settings, System ("Check now"). Not on a timer.

**What people see.** One line in Settings, System, labelled **Private files**. Setup and the installers show only the red result, or the note; a plain "Safe" stays on the System page. Exact wording:

- Not in the main site's public web space:
  > **Safe.** Your settings and backups are stored outside example.com's public web space, so no one can download them.
- In it, and refused:
  > **Safe.** Your Better-Cal folder sits inside example.com's public web space, but the server refuses every request for it, so no one can download your settings or backups.
- In it, the extra protection ignored, the secrets still guarded:
  > **Safe.** Your settings and backups are protected and can't be read, but this server lets anyone download Better-Cal's other files from example.com/better-cal/. Those are only the program itself, which is public anyway. To keep the folder tidy, move it out of public_html ([how](docs/install.md#choosing-where-to-put-it)).
- PHP not running there (the guard can't work):
  > **Exposed: fix this now.** Anyone can read your settings file at example.com/better-cal/.env.php, including your database password. This server isn't running PHP files for that folder. Move the better-cal folder out of public_html, or ask your host why PHP files there are downloadable instead of run ([details](docs/install.md#choosing-where-to-put-it)).
- The check couldn't run:
  > **Not checked.** Your host doesn't let Better-Cal fetch pages from your own website, so it couldn't test this. That doesn't mean anything is wrong. To check yourself, open example.com/better-cal/VERSION: "Forbidden" or "Not Found" is good; a version number means the folder is publicly visible.

An old `worker.log` adds: "Your cron job still writes its output into the Better-Cal folder, where it can be downloaded. Change it to: `<the new line>`."

**Honest limits.** It checks the main domain, the one address that realistically shares the folder, at the paths it could plausibly map to. An unrelated domain on the same account pointing at the folder isn't covered.

## Edge cases

- **open_basedir.** Nothing in this design writes above the app folder that doesn't already: backups go beside it when that's writable, else inside it (encrypted either way); the probes go inside it.
- **File managers and editors.** Editing `.env.php` by hand keeps working as long as the first line stays; if it doesn't, Better-Cal refuses to start with the message above rather than run exposed.
- **Copying settings during a manual update.** The no-SSH steps copy `.env.php` instead of `.env`.
- **CDNs in front** (Hostinger's, Cloudflare): the probes fetch through the same front door a visitor uses, so they test what a visitor would get. A cached old response could mislead; probe names are random and new each time.
- **A host serving PHP as another user** (old shared mod_php setups) needs `.env.php` world-readable, exposing it to other accounts on the server. Naming doesn't change that; it is unchanged from today and documented.

## Testing

- Unit tests: the guarded reader (guard present, missing, mangled, BOM, CRLF, UTF-16, a value containing `<?php`), conversion round trips, backup encryption round trips (and old plain backups still restoring), probe classification.
- Docker, the app inside a `public_html` under four servers: Apache honouring `.htaccess` (expect the second "Safe"); Apache with `AllowOverride None` (the third); Nginx serving the folder's static files with PHP for `.php` (the third, `.env.php` returning nothing); Nginx serving everything raw, PHP off for that path (Exposed). Plus the update path from 0.9.81 with a plain `.env`, converting on the way.
- A real shared host (Hostinger Premium, LiteSpeed) before 1.0.

## Sources

The research behind this design, with its evidence and links, is summarised in the tracking issue; the key sources: CISA AA24-016A (https://www.cisa.gov/news-events/cybersecurity-advisories/aa24-016a), Unit 42 on `.env` extortion (https://unit42.paloaltonetworks.com/large-scale-cloud-extortion-operation/), WPScan on configuration backups (https://wpscan.com/blog/wordpress-configuration-file-backups/), Matomo's guarded `config.ini.php` and its private-directories check (https://github.com/matomo-org/matomo/issues/17559), Plesk's Apache and nginx settings (https://docs.plesk.com/en-US/obsidian/customer-guide/websites-and-domains/hosting-settings/web-server-settings/apache-and-nginx-settings.72320/), Hostinger Agency's `.htaccess` (https://www.hostinger.com/support/how-to-enable-htaccess-on-agency-plans/), cPanel's document-root restriction (https://support.cpanel.net/hc/en-us/articles/360050710393), OpenLiteSpeed access control (https://docs.openlitespeed.org/security/access/), PHP's `__halt_compiler` (https://www.php.net/manual/en/function.halt-compiler.php).
