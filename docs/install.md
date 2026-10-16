# Installing Better-Cal

Better-Cal is plain PHP and MySQL with a no-build frontend. It runs on any host that gives you PHP 8.4+, MySQL 8 or MariaDB 10.6+, a cron job and a web server you can point at one directory, which includes ordinary shared hosting. Installing takes about fifteen minutes if those are in place. With a command line on the server (SSH), follow [Install](#install); without one, [Install without a command line](#install-without-a-command-line) does it all from your host's control panel and the browser.

## What you need

- **A server or hosting account**, and a domain or subdomain of Better-Cal's own (say `cal.example.com`, not `example.com/cal/`; [why](#choosing-where-to-put-it)) with HTTPS. SSH helps but isn't required. Sessions, the installable app and push reminders all require HTTPS.
- **PHP 8.4 or newer** with the extensions listed under [Requirements in detail](#requirements-in-detail). Most hosts let you pick the version in their control panel. The installer checks all of it and says what's missing.
- **MySQL 8.4 or MariaDB 10.6 or newer:** one empty database and a user for it.
- **Cron**, or any scheduler that can run a command every minute.

## Choosing where to put it

**Give Better-Cal a domain or subdomain of its own,** like `cal.example.com`. A folder of another site, like `example.com/cal/`, isn't supported, and that's on purpose.

Browsers keep sites apart by their address: everything on `example.com` counts as one site, whatever folder it's in. If Better-Cal lived at `example.com/cal/`, the rest of `example.com` (a WordPress install, its plugins, anything else there) could act as you inside Better-Cal: read your calendar, change it, use your session. A flaw in any of that would be a flaw in Better-Cal. A subdomain is a separate site to the browser, so that can't happen.

**Point the subdomain at `server/public`,** and, if your host allows, keep the `better-cal` folder outside the main site's web root (beside `public_html`, not inside it).

Some hosts (Hostinger, for one) only let a subdomain point at a folder inside `public_html`. That's fine for the browser, which goes by the address, not the folder, but it puts the whole `better-cal` folder where the main site serves files: without protection, `example.com/better-cal/` would hand out your settings, database password included. So:

- **Put it in a `better-cal` folder inside `public_html`** and point the subdomain at `public_html/better-cal/server/public`. On Hostinger: tick "Custom folder for subdomain" and enter `better-cal/server/public`; the panel makes that folder, and you extract the zip into `public_html/better-cal` with "Overwrite existing files" on.
- **The release protects itself there.** The settings file, `.env.php`, starts with a line that makes a web server show it as a blank page; backups are encrypted; and a `.htaccess` at the top of the folder refuses every request for anything in it on servers that read one (Apache, LiteSpeed). Only `server/public` serves the app. Better-Cal also answers only at the address in `BETTERCAL_BASE_URL`, so the copy reachable through the main domain redirects to the subdomain. [Keeping private files private](#keeping-private-files-private) has the details.
- **It checks itself.** At the end of setup and after each update, Better-Cal asks your main domain for a test file in its folder and tells you in plain words whether your settings could be downloaded. Settings, System shows the latest result under **Private files**, with a **Check now** button. If it ever says "Fix this now", do what it says: move the folder out of `public_html`, or ask your host to run PHP for it.

**If the hosting account also runs other sites, give Better-Cal its own hosting user** where the host allows (DreamHost and many others do, per domain). The subdomain protects you in the browser; on the server, every site under one user runs as the same account, so if one of them were compromised it could read Better-Cal's settings and database. A separate user keeps them apart.

## Install

Run these as the account that will own Better-Cal's files. PHP must be able to read them, and the worker's cron entry goes in this account's crontab. On shared hosting that's simply your own login. The examples install into `/srv/better-cal`; use any directory outside the web root.

1. **Get the release zip.** On the [releases page](https://github.com/Oshyan/better-cal/releases), the newest release has `better-cal-X.Y.Z.zip`: the app with its PHP libraries included, so there's nothing else to install. Unpack it where it will live:

   ```sh
   cd /srv
   curl -LO https://github.com/Oshyan/better-cal/releases/download/v0.9.84/better-cal-0.9.84.zip
   unzip better-cal-0.9.84.zip -d better-cal
   ```

   Use the newest version in place of `0.9.84`. That makes `/srv/better-cal`. The zip's files sit at its top level, so `-d better-cal` names the folder; without it they'd land in `/srv` itself. (Releases before 0.9.84 had a `better-cal` folder inside, and plain `unzip` made it.) Uploading the zip through your host's file manager and extracting it into a `better-cal` folder works too.

2. **Create a database and a user for it.** On shared hosting, use the control panel's database page and note the host, database name, user and password. On your own server:

   ```sh
   sudo mysql
   ```
   ```sql
   CREATE DATABASE bettercal CHARACTER SET utf8mb4;
   CREATE USER 'bettercal'@'localhost' IDENTIFIED BY 'choose-a-password';
   GRANT ALL PRIVILEGES ON bettercal.* TO 'bettercal'@'localhost';
   ```

3. **Run the installer:**

   ```sh
   php /srv/better-cal/server/bin/install.php
   ```

   It checks PHP and its extensions, then asks for the address people will open Better-Cal at, the database details (and tries them before going on), and your email and a password. It writes the settings file, `.env.php`, readable only by your account, with a generated session secret and push keys; creates the tables; and creates your account with a first calendar. It's safe to run again: with settings in place it uses them.

4. **Point the web server at `/srv/better-cal/server/public`.** Every request goes to `server/public/index.php`, except `/dav`, which goes to `dav.php`. Copy the [Nginx](#nginx-tested) or [Apache](#apache-24-tested-with-mod_php) example below; on shared hosting, set the domain's web directory in the control panel. No certificate yet? Let's Encrypt's `certbot` sets one up for Nginx or Apache in a couple of minutes.

5. **Start the worker.** It polls subscribed calendars, reads forwarded mail, sends reminders and tidies up. The installer prints the exact line, with your PHP's path; run `crontab -e` and add it. It looks like:

   ```
   * * * * * /usr/bin/php /srv/better-cal/server/bin/worker.php >/dev/null 2>&1
   ```

   On shared hosting, the control panel usually has a "cron jobs" page for this, with the schedule in separate fields: set them all to every (`*`) and paste the command part. The output is thrown away on purpose: a log file in the app folder could be downloadable on some hosts. Failing jobs show in Settings, System, and PHP errors go to a private log, `.runtime-cache/php-errors.php`.

   The PHP in the command is the server's plain `php` when that's new enough (it then follows the server's PHP upgrades), and otherwise this site's PHP named directly. Settings, System always shows the command as the server needs it now. If the worker stops running for 15 minutes (a PHP version removed, the cron job paused or deleted), the app shows a banner and, where email is set up, sends one email.

   The worker is optional for plain calendar use: the app and phones over CalDAV work without it. Reminders, subscribed calendars and Google refreshing, email import, prompt filters, place pins and plugins need it, and say so where you turn them on while it isn't running. A worker that never ran isn't treated as a problem; one you remove on purpose, Settings, System lets you mark as turned off.

6. **Check it.** `https://cal.example.com/api/v1/health` should show `{"ok":true,"db":true,...}`. Then open `https://cal.example.com`, sign in, and the welcome walks you through the first settings. A couple of minutes later, Settings, System should show the worker's jobs as healthy.

That's a working install. The rest is optional:

- **Push reminders** are set up by the installer; each person turns them on per device ([the app and reminders](app-and-reminders.md)).
- **Google Calendar**: needs an OAuth client of your own, from a free Google Cloud project. It takes about ten minutes; [google-calendar.md](google-calendar.md#one-time-setup-the-operator) has direct links to each console page.
- **Email** for reminders by mail and invitation replies: the SMTP settings in `.env.php`.
- More under [After installing](#after-installing): sample data to look around with, phone and desktop calendar apps (CalDAV), place search.

If something here didn't work as written on your host, please [report it](https://github.com/Oshyan/better-cal/issues).

### Install without a command line

For hosting where you have a control panel and a file manager (or FTP) but no SSH. Everything the installer does, a setup page in the browser does instead.

1. **Upload the release zip** (`better-cal-X.Y.Z.zip` from the [releases page](https://github.com/Oshyan/better-cal/releases)) with your host's file manager and extract it into a folder named `better-cal`. Most file managers ask where to extract, or for a folder name to extract into; the zip's files go straight into that folder. Put it outside your web root if the host allows (beside `public_html` rather than in it); if subdomains must point inside `public_html`, make it `public_html/better-cal`. If the panel already made that folder when you set up the subdomain, extract into it with overwrite on.
2. **Create a database and a user** on the control panel's database page. Note the host, database name, user and password.
3. **Point a subdomain of its own at `better-cal/server/public`** (say `cal.example.com`; [why a subdomain](#choosing-where-to-put-it)) in the control panel's domain settings, and turn on HTTPS for it (most panels have a Let's Encrypt switch).
4. **Open the address in your browser.** You'll see Better-Cal's setup page. To show it's your server, it asks for a code it has just written into the file `better-cal-code.php` in the `better-cal` folder: open that file in the file manager and type the code in. (Anyone who reaches the page before you can't get further without that file.)
5. **Fill in the form:** the address, the database details (checked before anything is saved) and your account. Setup creates the tables, saves the settings in `.env.php` (readable only by your account) and makes your account. From then on the setup page is gone for good. If its check of your main domain finds a problem, the last page says so.
6. **Add the cron job** it shows you, in the control panel's cron or scheduled-tasks page. Most panels take the schedule in separate minute, hour, day, month and weekday fields: set them all to every (`*`), or pick "once per minute", and paste the command it shows. If the panel has a "PHP" job type that fills in the start of the path, choose its "custom" type instead and paste the whole command, since that names the PHP version your site runs on. Then sign in.

It needs PHP to be able to write to the `better-cal` folder, which on shared hosting it normally can. If it can't, the page says so.

### From git instead of the zip

For following development or making changes: `git clone https://github.com/Oshyan/better-cal.git /srv/better-cal`, `git checkout` the newest release tag, then install the PHP libraries with [Composer](https://getcomposer.org/download/) (`cd server && composer install --no-dev`) before running the installer. Updating works from a checkout too.

### By hand, without the installer

What `install.php` does, step by step, if you'd rather:

1. `cp .env.example .env.php && chmod 600 .env.php` (keep its first line, which makes a web server show the file as a blank page), then fill in `BETTERCAL_BASE_URL`, the database in `BETTERCAL_DB_DSN`, `BETTERCAL_DB_USER`, `BETTERCAL_DB_PASS`, and `BETTERCAL_SESSION_SECRET` (`openssl rand -hex 32` makes one). Everything else in the file is optional and explained where it appears.
2. `php server/bin/migrate.php` creates the tables.
3. `php server/bin/seed.php --email=you@example.com` creates your account; it asks for the password without showing it. From a script, set `BETTERCAL_SEED_PASSWORD` instead; never put a password on the command line, where other users and the shell history can see it.
4. Optional: `php server/bin/vapid.php --generate`, and put the keys it prints into `.env.php`, for push reminders.

## Requirements in detail

- PHP 8.4 or newer (8.4.1 or later) with these extensions: `pdo_mysql`, `curl`, `mbstring`, `xml` (dom, simplexml, xmlreader, xmlwriter: CalDAV needs them), `zip`, plus `json`, `openssl`, `sodium`, `ctype`, `iconv` and `fileinfo`, which nearly every PHP build already has. `composer check-platform-reqs --lock --no-dev`, run in `server/`, lists anything missing.
  - Best with `proc_open` allowed and the PHP command-line binary installed in PHP's own bin directory (`php8.4-cli` below does this on Debian and Ubuntu). Every outbound request (feeds, Google, place search, plugins) then looks up its address in a short-lived child process with a time limit, so a slow DNS server cannot stall it. Where either is missing (some shared hosts disable `proc_open`), the lookup runs in-process instead and the PHP error log says so once: everything still works and private addresses are still refused, but a stuck DNS server can hold a request until the system resolver gives up. Outbound requests connect directly: proxy variables such as `HTTPS_PROXY` are ignored.
  - Shared hosting: pick PHP 8.4 or 8.5 in the host's panel (DreamHost offers both as of October 2026; elsewhere, check the host's PHP version picker).
  - Debian 13 and Ubuntu 26.04 ship PHP 8.4 and 8.5 respectively: `apt install php8.4-cli php8.4-mysql php8.4-curl php8.4-mbstring php8.4-xml php8.4-zip` (use `8.5` on Ubuntu 26.04), plus `libapache2-mod-php8.4` or `php8.4-fpm` for the web server.
  - Ubuntu 24.04 and Debian 12 ship older PHP. Add the widely used packages by Ondřej Surý first: `sudo add-apt-repository ppa:ondrej/php` on Ubuntu (Debian: https://packages.sury.org/php/), then the `php8.4-*` packages above.
- Composer, only to install from git rather than the release zip.
- MySQL 8.4 or MariaDB 10.6 or newer, with one empty database and a user with full rights on it. Tested on MySQL 8.4 (Percona Server) and MariaDB 10.6 and 10.11. MySQL 8.0 should work, since nothing here needs newer SQL, but it is past end of life and untested.
- A cron entry (or any scheduler) that can run a PHP script every minute.
- HTTPS.
- Node 20+ only if you want to run the frontend tests, `scripts/deploy.sh` (which runs them) or the MCP server. The app itself needs no build step.

### Where it has been tested

- **Production:** Nginx with PHP-FPM 8.4 and MySQL 8.4, on Linux.
- **Clean-install test (2026-10-03):** Ubuntu 24.04 with Apache 2.4 and mod_php 8.3 (the minimum then; it is 8.4 since 0.9.0, and the GitHub check installs on 8.4 on every push), MariaDB 10.11 (and MariaDB 10.6 for the migrations, the smoke test and the worker), following this page from an empty machine. Everything below was checked there: install, migrations, sign-in, events and undo, search, repeating events, CalDAV and API tokens through Apache, a subscribed feed, a plugin, the worker under cron.
- **Repeated on 2026-10-05** with PHP 8.4 from the Ubuntu PPA described above (mod_php, only the listed extensions) and MariaDB 10.11: install, migrations, the worker under cron, and the first sign-in to a new account with its welcome.
- **Shared hosting (2026-10-15):** LiteSpeed with PHP chosen in the control panel and the app folder inside `public_html` (Hostinger), installed from the release zip with the setup page and a control-panel cron job, then updated by extracting a newer zip over it. And DreamHost shared hosting, installed by someone other than the maintainer following this page.

## Updating

Settings, About shows the running version, and the app tells you when a release is out (it checks GitHub once a day; Settings, About can limit that to security releases or turn it off). The app itself never downloads or installs anything. With SSH, updating is one command; without it, a few steps in your host's file manager and one button. A one-click update from the app is planned ([#139](https://github.com/Oshyan/better-cal/issues/139)).

Each [release page](https://github.com/Oshyan/better-cal/releases) lists what changed and ends with an **Operators** line: what the update needs from you, such as "no migration", a new setting, or a newer PHP. Skipping versions is fine, since the migration applies everything in between, but read the Operators lines of the ones you skip as well.

### With the updater

As the account that owns the files:

```sh
php /srv/better-cal/server/bin/update.php
```

It finds the newest release, checks it the way the app's update notice does (a published, unchangeable GitHub release with a valid manifest and signature), checks the release's signature against the signing keys this copy of Better-Cal ships with ([releasing.md](releasing.md#signing)), and shows its Operators line. If you say yes, it:

1. **puts the site in maintenance mode**, so it shows "updating" for a minute and the worker pauses;
2. **backs up the database** into `/srv/better-cal-backups/`, written by PHP itself, so it works without `mysqldump`, and encrypted;
3. **installs the new version.** From a release zip, it downloads the new zip, checks its SHA-256 against GitHub's, and unpacks it beside the current copy. It carries over your settings, plugins you added and per-host files like `.user.ini`, then swaps the two folders, so the previous version stays whole in `/srv/better-cal-<old version>`. From a git checkout, it checks out the release tag and runs Composer. If the release changes which keys may sign the next one, it says so;
4. **runs the new version's database changes**, brings your private files up to date (settings still in `.env` move to `.env.php`, older backups get encrypted), and brings the site back.

`--check` only says whether there's a release; `--yes` skips the question. One update runs at a time: a second one, from another terminal or the database update page, says so and changes nothing. It needs to be able to create folders beside the app directory (in `/srv` here), which on shared hosting your home directory allows. Open browsers and the installed app pick up the new version on their next load.

**If something goes wrong,** the updater stops and says so. Before the new code is in place, it puts the site back on the current version. Once the new code is in place, it leaves the site in maintenance mode, and

```sh
php /srv/better-cal/server/bin/update.php --rollback
```

puts the previous version and the database back as they were before the update. It also tidies up after an update that was cut off partway (a closed terminal, a killed process), whatever step it stopped at, and works after an update that finished but misbehaves; do it soon, since anything entered since the backup is lost. Going back to a version before 0.9.83, which only reads `.env`, writes `.env` from `.env.php` for it and says so. Please [report what happened](https://github.com/Oshyan/better-cal/issues).

The updater keeps the newest two previous versions and database backups, and removes older ones (each copy is a whole app, thousands of files, and shared hosts limit how many an account may have). Copies you made yourself, under any other name, are never touched; Settings, System lists them under Private files. Backups are encrypted with a key made from your session secret, so keep a copy of `.env.php` with any backup you copy elsewhere. `php server/bin/backup.php --decrypt FILE OUT.sql.gz` turns one into a plain `.sql.gz` for phpMyAdmin or the `mysql` client.

### Without a command line

1. **Download the new release zip** and upload it with your host's file manager, next to the current `better-cal` folder (not into it), and extract it into a new folder named `better-cal-new`. (A zip from before 0.9.84 has its own `better-cal` folder inside; move that up a level.)
2. **Copy your own files across** from `better-cal` into `better-cal-new`: `.env.php` (or `.env`, on a version before 0.9.83), and any plugins you added under `server/plugins/`. Names starting with a dot are hidden in some file managers; look for a "show hidden files" setting.
3. **Swap the folders by renaming them:** `better-cal` to `better-cal-old`, then `better-cal-new` to `better-cal`. Your domain and cron job keep pointing at the same place.
4. **Open Better-Cal.** If the new version has database changes, you'll see "Database update needed" instead of your calendar (an installed app shows the same, with a button to the update page). Enter the code it writes into `better-cal-code.php`, as at setup, and press **Back up and update the database**: it backs up first, beside the `better-cal` folder (or inside it, if the folder above isn't writable), then applies the changes. If there are no changes, Better-Cal simply opens on the new version.

If the database update itself fails, the page offers **Restore the database as it was**; then rename the folders back. If the host cuts the page off partway (some end requests after 30 or 60 seconds), Better-Cal shows the same page again instead of "updating", to run it again or restore. To go back after an update that worked, rename the folders back and open Better-Cal: it sees that the database is newer than its files and offers to restore the backup made before the update (from 0.9.86 on; for an older version, use your host's own backups). The backups are encrypted; turning one into a file phpMyAdmin can import takes a command line: `php server/bin/backup.php --decrypt`. Keep `better-cal-old` until you're happy with the new version. Unlike the updater, this route doesn't check the release's signature for you, so download only from the [releases page](https://github.com/Oshyan/better-cal/releases).

### By hand, with SSH

The updater's steps yourself, as the user that owns the files, in the app directory (`/srv/better-cal` here):

1. **Back up the database**, and keep a copy of your settings:

   ```sh
   mysqldump --single-transaction -u DB_USER -p DB_NAME > ~/bettercal-before-update.sql
   cp .env.php ~/bettercal-settings-backup.php
   ```

   Your host's own database backup works too, as long as you can restore it.
2. **Get the new code.**
   - From a git clone: `git fetch --tags` and then `git checkout v0.9.74`, with the version you're updating to, then `cd server && composer install --no-dev && cd ..`. Releases are tags; `main` can be ahead of the newest release.
   - From a release zip: unpack the new one into a fresh directory beside the current one (`unzip better-cal-X.Y.Z.zip -d /srv/better-cal-new`), copy `.env.php` into it along with any plugins you added under `server/plugins/`, then swap the two: `mv /srv/better-cal /srv/better-cal-old && mv /srv/better-cal-new /srv/better-cal`. Your web server and cron entry keep pointing at the same path.
3. **Apply database changes:** `php server/bin/migrate.php`. It lists each change it applies, or none.
4. **Check it:** `https://your-host/api/v1/health` should say `{"ok":true,"db":true,...}`, and Settings, About should show the new version.

For the few seconds between steps 2 and 3, the new code runs against the old database. To avoid even that, create an empty file named `.maintenance` in the app directory before step 2 (the site shows "updating" and the worker pauses) and delete it after step 3.

If the migration stops with an error, don't keep using the app: restore the backup (`mysql -u DB_USER -p DB_NAME < ~/bettercal-before-update.sql`), go back to the previous code (`git checkout` the old tag and run Composer, or swap `/srv/better-cal-old` back), and please [report the error](https://github.com/Oshyan/better-cal/issues). If the health check fails, the PHP error log usually says why; most often it's a missing PHP extension or version a release's Operators line asked for.

### The deploy script (optional)

`scripts/deploy.sh` is the maintainer's deploy tool, not something you need: it updates from a separate machine over rsync and ssh, with the test suites and a backup first. It ships the last commit, not the working tree, so commit first: uncommitted edits are left out. It runs the server, smoke and static suites, deploy-security checks, dependency-pin checks and the vendor check on exactly those files first and refuses to ship a red tree. It then backs up the app directory and MySQL/MariaDB database before changing anything, into the configured root-owned backup directory, and finishes with the health check and `smoke.php`. The application account reads the backup sources but cannot write the private destination. To use it, copy `scripts/deploy.env.example` to `scripts/deploy.env`, fill in the host and paths, and run it. It assumes a Debian-style host with sudo, composer, mysqldump, `flock` and cron; `scripts/deploy-dev.sh` ships any branch to a second, isolated install on the same host. SQLite remains useful for tests, but this production deploy helper deliberately refuses an SQLite DSN; use SQLite's own online-backup mechanism separately.

### Development deployments

`scripts/deploy-dev.sh` deploys the working tree to a separately configured dev directory and database. Provision `DEV_DIR`, `DEV_DB`, the dev settings file and its distinct `BETTERCAL_SESSION_SECRET` before using it. Database accounts and grants are operator-owned configuration: the deploy verifies them but never creates, locks, unlocks or rewrites them.

Three flags in the ignored `scripts/deploy.env` control the security/usability tradeoffs; `scripts/deploy.env.example` contains the complete configuration:

- `DEV_ISOLATION_MODE=strict|shared` defaults to `strict`. Strict mode requires separate Unix, database and PHP-FPM identities and verifies that the two application identities cannot read one another's database or settings. Explicit `shared` mode permits those identities and credentials to be reused and prints a warning on every deploy. The application directories, database names and session secrets must still differ. A shared PHP-FPM service is never stopped or restarted; if opcode timestamp checks are disabled, changed dev code may remain stale until an operator-chosen reload.
- `DEV_EXTERNAL_SERVICES=disabled|enabled` defaults to `disabled`. Enabling it permits integrations deliberately configured in the dev settings and warns that mail, push, AI, maps and connected calendars can have real side effects or cost. A clone never copies production Google connections or active plugin settings: reconnect or reconfigure those in dev. Receive-only ICS subscriptions are separate from this switch.
- `DEV_CLONE_SUBSCRIPTIONS=preserve|strip` defaults to `preserve`. A `CLONE_DB=1` clone re-encrypts ordinary ICS source addresses with the dev session secret so they keep synchronizing. `strip` removes the synchronization addresses and leaves their imported events as local snapshots.

Every `CLONE_DB=1` operation first imports production into a private, randomly named staging schema, proves neither application identity can access it, sanitizes and verifies it there, and only then replaces the dev database. Copied sessions, trusted devices, API tokens, push subscriptions, outbound feeds, Google connections, active jobs and plugin integration configuration are removed. Plugins and Google-backed calendars remain as inert data snapshots; enable and configure them again in dev when that is the behavior being tested. All copied account passwords are replaced with `DEV_LOGIN_PASSWORD`, which belongs only in the ignored deployment configuration.

## Dependencies and advisories

PHP dependencies are Composer's and pinned in `server/composer.lock`; `composer audit --no-dev --locked` checks them against the Packagist advisory database and is a CI/deployment gate. The frontend has no package manager: its libraries (Preact, htm, Squire, DOMPurify, Leaflet) are committed under `web/vendor/` and pinned in `web/vendor/manifest.json` with their npm package, version and the sha256 of the shipped file. `node scripts/vendor.mjs --verify` confirms the tree matches the manifest, and `--check-upstream` confirms each file against the published release; [web/vendor/README.md](../web/vendor/README.md) lists them. Dependabot and the weekly dependency-watch workflow notify maintainers of Composer, GitHub Actions, PHP-image and vendored-browser-library updates; updates remain reviewed rather than automatic. See [`dependencies.md`](dependencies.md) for the decision policy.

The worker checks the project's GitHub releases once a day. Update notices are enabled by default and can be set to **All releases**, **Security updates only**, or **Off** in Settings, About; **Check now** always remains available. The app only reports a release and links to its notes; it never downloads or installs application code. `server/bin/update.php`, which you run, does: only a published, immutable release whose manifest and zip match GitHub's SHA-256 digests and whose signature verifies against the keys in `server/config/release-keys.php`. A release's cumulative secure-version floor keeps an older vulnerable installation warned even if a later release is otherwise routine.

## How requests are routed

This is all a web server needs to know, and it is the same on any of them:

- The document root is `server/public`.
- Requests whose path starts with `/dav` go to `server/public/dav.php` (CalDAV).
- **Every other request goes to `server/public/index.php`**, whatever the path. It serves the API under `/api/v1`, public feeds under `/feed/`, the frontend's static files under `/assets/`, plus `/sw.js`, the manifest and favicons, and the app's HTML for any other path (`/add`, `/subscribe`, `/share` and so on are all the same single-page app).
- The app reads the original request path from `REQUEST_URI`, so the server must rewrite internally, not redirect.
- The `Authorization` header must reach PHP. API tokens and CalDAV logins both arrive in it.

The app sets its own cache headers on the static files it serves (`server/src/Http/StaticFiles.php`), so you do not have to configure caching at all: files under `/assets/vendor/` are cached for 30 days as immutable, and everything else, including `sw.js` and the HTML, is `no-cache` with an ETag, which means the browser revalidates on each load and gets a bodyless 304 unless a deploy changed the file. The one rule that matters if you override this: **never let `sw.js` be cached without revalidation**, or browsers will not notice new versions.

`server/public/assets` is a symlink into `web/`. It exists so a web server can serve those files directly as an optimisation. You can ignore it; routing everything to `index.php` works without it. **`/sw.js` must always go to `index.php`**: the app fills in the service worker's version as it serves it. Sent straight from disk, the worker runs without one and the app loses offline start (it still works online).

## Nginx (tested)

Minimal, everything through PHP:

```nginx
server {
    listen 443 ssl http2;
    server_name cal.example.com;
    root /srv/better-cal/server/public;
    client_max_body_size 25m;                      # calendar imports

    location ^~ /dav {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/dav.php;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock; # match your PHP-FPM socket
    }
    location = /.well-known/caldav { return 301 /dav/; }

    location / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
}
```

Optional: let Nginx serve the static files itself, which is what the production install does. Add these above `location /`, mirroring the app's cache policy:

```nginx
    location ^~ /assets/vendor/ {
        add_header Cache-Control "public, max-age=2592000, immutable";
        try_files $uri =404;
    }
    location ^~ /assets/ {
        types { application/manifest+json webmanifest; text/javascript js mjs; text/css css; image/svg+xml svg; image/png png; font/woff2 woff2; }
        add_header Cache-Control "no-cache";
        try_files $uri =404;
    }
```

Do not add a rule that serves `/sw.js` from disk; see above.

**Hosting panels (CloudPanel, and many others) add a catch-all rule for static file extensions**, typically `location ~* \.(css|js|png|...)$` with `expires max`. It catches `/sw.js` before your `location /` does and answers it from disk, or with a 404 since there is no such file. Add an exact rule that sends it to the app; an exact `location =` always wins over a regex. With PHP-FPM directly:

```nginx
    location = /sw.js {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
```

Behind a panel that proxies `location /` to an inner server (CloudPanel does: `proxy_pass http://127.0.0.1:8080`), use the same `proxy_pass` and headers as its `location /` inside `location = /sw.js { ... }`. To check: `curl -sI https://your-host/sw.js` should show `ETag: W/"bc-` followed by twelve hex digits.

The `types` block is there because `.webmanifest` is missing from Nginx's default MIME map, and a `types` block replaces the inherited map inside that location, so every extension the frontend uses has to be listed. Nginx follows the symlinks by default.

## Apache 2.4 (tested with mod_php)

Needs `mod_rewrite` (`a2enmod rewrite` on Debian and Ubuntu). In the virtual host:

```apache
<VirtualHost *:443>
    ServerName cal.example.com
    DocumentRoot /srv/better-cal/server/public

    <Directory /srv/better-cal/server/public>
        Require all granted
        AllowOverride None
        Options -Indexes

        # Hand the Authorization header to PHP. Needed when PHP runs as FPM or
        # CGI; harmless under mod_php. Requires Apache 2.4.13+.
        CGIPassAuth On

        RewriteEngine On
        RewriteRule ^dav(/|$) dav.php [END]
        RewriteRule ^ index.php [END]
    </Directory>
</VirtualHost>
```

This exact block was used in the clean-install test under mod_php: CalDAV, API tokens and every page route worked. For a plain-HTTP test on your own machine, use `<VirtualHost *:80>` and an `http://localhost:PORT` base URL; browsers treat localhost as secure, so sign-in works without a certificate.

`[END]` rather than `[L]` matters: in a directory context `[L]` starts another rewrite pass, which would send `dav.php` on to `index.php`. This routes every request through PHP, including static files, so the symlinks and `FollowSymLinks` are not involved and the app's cache headers apply as they are.

**On shared hosting, or anywhere you cannot edit the virtual host, there is nothing to add:** the repository ships `server/public/.htaccess` with the same routing, using the older way of passing the Authorization header, which works where `CGIPassAuth` is not allowed. The host only has to allow `FileInfo` overrides for that directory, which shared hosts do. Tested on Apache 2.4 with `AllowOverride FileInfo` and nothing else: every page, CalDAV and API tokens. It has not yet been tried on an actual shared host (issue #22). It should behave the same on LiteSpeed, which reads `.htaccess` rewrites, but that hasn't been tried yet either. With a virtual host like the one above (`AllowOverride None`), the file is ignored.

If the host forces the document root to a directory you do not control (`public_html`), make that directory a symlink to `server/public`, or put the repository beside it and symlink `index.php` and `dav.php` in; both files locate the rest of the app relative to their real path.

Signs that the `Authorization` header is being dropped: signing in through the browser works, but API tokens return 401 and CalDAV clients keep asking for the password.

## Behind a proxy or CDN

Sign-in attempts are rate limited per network address. If something on another address sits in front of the web server (Cloudflare, a load balancer, a separate reverse-proxy host, a container ingress), PHP sees that proxy's address for every visitor, so they would all share one limit. Set `BETTERCAL_TRUSTED_PROXIES` in `.env.php` to the proxy's IPs or CIDR ranges and the real address is read from `X-Forwarded-For`, from the right-hand end, which is the part a client cannot forge. Leave it empty otherwise: by default the header is ignored. Nginx or Apache on the same machine handing requests to PHP-FPM is not a proxy in this sense and needs nothing.

Better-Cal answers only at the host in `BETTERCAL_BASE_URL` ([why](#choosing-where-to-put-it)): a request for any other host is redirected there, or refused with 421. If a proxy in front rewrites the `Host` header to an internal name, or the same install is deliberately reached by a second name, list those in `BETTERCAL_ALLOWED_HOSTS`, comma separated.

**Check that your web server is not already believing visitors about their address.** The app can only be as right as the address the web server hands it. Nginx's `real_ip` module (`set_real_ip_from`, `real_ip_header`) rewrites the client address from a header, and it should only do so for peers you control. CloudPanel's `/etc/nginx/nginx.conf` (seen in CloudPanel 2.5) includes `set_real_ip_from 0.0.0.0/0;` and leaves the header at nginx's default, `X-Real-IP`, which trusts that header from everyone: any visitor can then send `X-Real-IP: 1.2.3.4` and be counted as that address, which lets them dodge the per-address limit and aim a block at someone else's address. Keep the loopback and private ranges (CloudPanel's inner server on port 8080 needs `127.0.0.1`) and remove the `0.0.0.0/0` line, or replace it with your CDN's published ranges if you use one. To test: send one wrong password with a made-up `X-Real-IP` header and see which address the block in Activity names after ten of them, or look at `rate_events`.

## Keeping private files private

A few of Better-Cal's files must never be downloadable: the settings file (database password, session secret, keys), database backups, the one-time setup code and PHP's error log. They're protected wherever the folder sits:

- **The settings file starts with a guard line.** `.env.php` begins with `<?php __halt_compiler();`: asked for over the web, the server runs it as PHP, which stops at once, so it shows a blank page. Better-Cal reads it as text, never runs it. Keep that first line when you edit the file. If it's deleted, Better-Cal puts it back; if something else ends up in front of it, Better-Cal refuses to start and says what to fix.
- **`.env` from before 0.9.83 still works** until it's converted. The updater, the database update page and the worker convert it on their own when it belongs to the account they run as; `php server/bin/settings.php --convert` does it by hand, run as the account that owns `.env` (root, on a server set up by the deploy script).
- **Backups are encrypted** (libsodium's XChaCha20-Poly1305) with a key made from `BETTERCAL_SESSION_SECRET`, and end in `.bcbackup`. The key comes from the settings file, so encryption protects a backup only while that file stays private: it covers a server that hands out the backups folder but not the settings. Restoring needs the settings they were made with, so keep a copy of `.env.php` with any backup you store elsewhere, and keep the two as carefully as each other. `php server/bin/backup.php --decrypt FILE OUT.sql.gz` writes a plain copy for other tools; delete it when you're done.
- **The setup code, the update record and PHP's error log are guarded the same way.** Where a host sets PHP to write errors to a file beside the script (a bare `error_log`, common on shared hosting), that file would sit in a served folder, so Better-Cal sends errors to `.runtime-cache/php-errors.php` instead.
- **A `.htaccess`** at the top of the folder refuses every request on servers that read one.

**Settings, System, Private files** says whether this holds on your host. Better-Cal writes a test file into its folder and asks your main domain for it (`example.com` and `www.example.com` for `cal.example.com`), at the place that domain would serve the folder if it sits in its web space:

- **Safe** comes in two forms: the folder is in the main domain's web space but the server refuses every request for it; or the main domain reaches it, but the settings file shows as a blank page there and the backups are encrypted.
- **Probably safe** means no address it tried serves the folder. That's also the most it says for a folder outside `public_html` and the like, since a web root can be named anything. It can't rule out an address it doesn't know about, so it says how to check that one yourself.
- **Fix this now** means the settings file can be read from the web: the server hands out PHP files as text in that folder, or an unconverted `.env` sits there. Move the folder out of the web root, ask your host to run PHP there, or convert `.env`. Then change what the file holds, since it may already have been copied: the database password first, then any mail, Google or other service passwords and keys. `BETTERCAL_SESSION_SECRET` is the hard one: a new one signs everyone out, means connecting Google again, and makes older backups unreadable, so change it, take a fresh backup, and delete the old ones.
- **Not checked** means your host doesn't let a site fetch pages from its own websites, which is common. The message says how to check by hand.

It runs at the end of setup and of each update, and when you press **Check now** (at most once a minute). It's not on a timer. Notes under the result point out leftovers worth deleting, like an old `worker.log` in the folder.

**File permissions.** Keep `.env.php` readable by the PHP user and nobody else: on shared hosting that is `chmod 600 .env.php`, owned by your own account, which is also the user PHP runs as. For the root-backed deploy script, provision it once as `root:APP_USER` mode `0640` (for example, `chown root:bettercal .env.php && chmod 640 .env.php`). The deploy validates that it is a readable regular file and refuses a symlink, or both `.env` and `.env.php` at once, but deliberately does not change ownership or mode through the app-owned directory. Converting a root-owned `.env` keeps its owner, group and mode: `sudo php server/bin/settings.php --convert`.

To change settings later without editing the file on the server, put them in `scripts/server.env` on your own machine (gitignored, `KEY=VALUE` lines) and run `scripts/push-settings.sh` (or `scripts/push-settings.sh dev` with `scripts/server-dev.env` for the dev install). It adds or replaces only those keys in the server's settings file, keeps every other line (the guard line included), saves a copy to `/root/bettercal-env-backups` first, refuses a symlink, keeps the file's owner and mode, and never prints a value. It is separate from deploying on purpose: a deploy never writes the settings. They apply on the next request; nothing needs restarting.

Production backups use a separate privilege boundary. `BACKUP_DIR` must be one direct child of `/home` or `/var/backups`; the deploy creates it as `root:root` mode `0700`, creates each archive as mode `0600`, and keeps the newest five app and database copies. The app user supplies the readable source stream but cannot create, replace or remove backup files. Installations that used an older deploy script may still have a legacy `backups` directory beside the app. After confirming a new backup exists under `BACKUP_DIR`, archive or remove that legacy directory as a separate, deliberate maintenance step.

## After installing

- `php server/bin/smoke.php` checks that the events window, a single event and the calendar list serialize against your real data.
- To look around before moving anything in, `php server/bin/demo-seed.php` fills an empty account with invented sample calendars, events and people, dated around today. It refuses to run once the account has events of its own.
- CalDAV clients connect to `https://your-host/dav/` with your email and either your password or an API token (`php server/bin/token.php --create --name="My phone"`). See [caldav.md](caldav.md).
- Place search works out of the box on Photon (photon.komoot.io, free, no key). For faster or better results you can use LocationIQ or Stadia Maps with your own key: set `BETTERCAL_PLACE_SEARCH` and the key in `.env.php` (see `.env.example`), and Settings, Location & maps shows which service is in use, with Photon still answering whatever the chosen service can't. [Geocoding and place search](geocoding.md) compares the services and explains the choice. If you restrict a LocationIQ key to source addresses, use the server's IPv4 address; Better-Cal reaches LocationIQ over IPv4. **Stadia Maps:** Better-Cal saves the coordinates of places you pick, and Stadia Maps' terms allow keeping them only on a paid plan that includes storing results, so check [Stadia's current plans](https://stadiamaps.com/pricing/). Making sure your plan fits is your responsibility; Better-Cal does not check.
- Set your Home time zone under Settings, General. The screen always follows the device you are using; Home is the clock all-day reminders fire on and the zone of events created for you by email, plugins and the API.

## Removing Better-Cal

To take an install down completely. If you might want your calendars again, keep a copy first: **Settings, Account, Export everything** downloads a zip with one `.ics` file per calendar, which Better-Cal imports in one step and other calendar apps import a file at a time. A database backup together with `.env.php` (a backup needs it to be read) keeps everything else too: people, labels, filters and settings.

1. **The cron job.** Delete it first (control panel, or `crontab -e`), so nothing runs while the rest goes.
2. **The address.** Remove the subdomain or domain you pointed at `server/public`, or its web server configuration (the Nginx or Apache site), and its HTTPS certificate if it has one of its own.
3. **The files.** Delete the `better-cal` folder. Its settings, setup code and logs are inside it. Also delete anything beside it that Better-Cal made: `better-cal-backups` (database backups) and any `better-cal-<version>` folders kept by updates.
4. **The database.** Delete the database and its database user (control panel, or `DROP DATABASE` and `DROP USER` in MySQL).
5. **Your devices.** Remove the home-screen app where you installed it, and clear the site's data in each browser you used it in (its offline copy and notification sign-up). Calendar apps connected over CalDAV need their account removed too.
6. **Google, if you connected it.** Remove Better-Cal's access in your Google Account (Security, Third-party apps and services). The OAuth client in your Google Cloud project, if you made one only for this, can go as well.
