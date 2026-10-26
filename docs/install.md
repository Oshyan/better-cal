# Installing Better-Cal

Better-Cal is plain PHP and MySQL with a no-build frontend. It runs on any host that gives you PHP 8.4+, MySQL 8 or MariaDB 10.6+, a cron job and a web server you can point at one directory, which includes ordinary shared hosting. Installing takes about fifteen minutes once those are in place. With a command line on the server (SSH), follow [Install](#install); without one, [Install without a command line](#install-without-a-command-line) does it all from your host's control panel and the browser.

## What You Need

- **A server or hosting account**, and a domain or subdomain of Better-Cal's own (`cal.example.com`, not `example.com/cal/`; [why](#choosing-where-to-put-it)) with HTTPS, which sessions, the installable app and push reminders all require. SSH helps but isn't required.
- **PHP 8.4 or newer** with the extensions under [Requirements in detail](#requirements-in-detail). Most hosts let you pick the version in their control panel, and the installer says what's missing.
- **MySQL 8 (8.4 tested) or MariaDB 10.6 or newer:** one empty database and a user for it.
- **Cron**, or any scheduler that can run a command every minute.

## Choosing Where to Put It

**Give Better-Cal a domain or subdomain of its own,** like `cal.example.com` or `example-calendar.com`. A folder of another site, like `example.com/cal/`, isn't supported, on purpose: browsers treat everything on `example.com` as one site, so the rest of it (a WordPress install, its plugins, anything else there) could act as you inside Better-Cal, and a flaw in any of that would be a flaw in Better-Cal. A subdomain is a separate site to the browser.

**Don't test on a temporary address** your web host may provide. The security self-check won't be accurate, and you'd have to change the base URL later, along with anything that depended on it.

**Point the domain or subdomain at `server/public`,** and, if your host allows, keep the `better-cal` folder outside the main site's web root (beside `public_html`, not inside it).

Some hosts (Hostinger, for one) only let a subdomain point at a folder inside `public_html`. That's fine for the browser, but it puts the whole `better-cal` folder where the main site serves files. So:

- **Put it in `public_html/better-cal`** and point the subdomain at `public_html/better-cal/server/public`. On Hostinger: tick "Custom folder for subdomain" and enter `better-cal/server/public`; the panel makes that folder, and you extract the zip into `public_html/better-cal` with "Overwrite existing files" on.
- **The release protects itself there**: the settings file shows as a blank page, backups are encrypted, a `.htaccess` refuses requests on servers that read one, and the copy reachable through the main domain redirects to the subdomain ([Keeping private files private](#keeping-private-files-private)).
- **It checks itself.** At the end of setup and after each update, Better-Cal asks your main domain for a test file in its folder and tells you whether your settings could be downloaded. If it ever says "Fix this now", do what it says.

**If the hosting account also runs other sites, give Better-Cal its own hosting user** where the host allows (DreamHost and many others do, per domain). Every site under one user runs as the same account, so a compromised one could read Better-Cal's settings and database.

## Install

Run these as the account that will own Better-Cal's files (on shared hosting, simply your own login). PHP must be able to read them, and the worker's cron entry goes in this account's crontab. The examples install into `/srv/better-cal`; use any directory outside the web root.

Installing from a downloaded zip means trusting that download; to check it first, see [Optional verification before upload](#optional-verification-before-upload).

1. **Get the release zip.** The newest release on the [releases page](https://github.com/Oshyan/better-cal/releases) has `better-cal-X.Y.Z.zip`, the app with its PHP libraries included. Unpack it where it will live (use the newest version in place of `1.0.0`):

   ```sh
   cd /srv
   curl -LO https://github.com/Oshyan/better-cal/releases/download/v1.0.0/better-cal-1.0.0.zip
   unzip better-cal-1.0.0.zip -d better-cal
   ```

   The zip's files sit at its top level, so `-d better-cal` names the folder. Uploading the zip through your host's file manager and extracting it into a `better-cal` folder works too.

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

   It checks PHP and its extensions, then asks for the address people will open Better-Cal at, the database details (and tries them), and your email and a password. It writes the settings file, `.env.php`, readable only by your account, with a generated session secret and push keys; creates the tables; and creates your account with a first calendar. It's safe to run again: with settings in place it uses them.

4. **Point the web server at `/srv/better-cal/server/public`.** Copy the [Nginx](#nginx-tested) or [Apache](#apache-24-tested-with-mod_php) example below; on shared hosting, set the domain's web directory in the control panel. No certificate yet? Let's Encrypt's `certbot` sets one up for Nginx or Apache in a couple of minutes.

5. **Start the worker.** It polls subscribed calendars, reads forwarded mail, sends reminders and tidies up. The installer prints the exact line, with your PHP's path; run `crontab -e` and add it. It looks like:

   ```
   * * * * * /usr/bin/php /srv/better-cal/server/bin/worker.php >/dev/null 2>&1
   ```

   On shared hosting, use the control panel's "cron jobs" page: set every schedule field to every (`*`) and paste the command. The output is thrown away on purpose (a log file in the app folder could be downloadable on some hosts); failing jobs show in Settings, System, and PHP errors go to a private log, `.runtime-cache/php-errors.php`. Settings, System always shows the command as the server needs it now, and if the worker stops running for 15 minutes the app shows a banner and, where email is set up, sends one email.

   Plain calendar use works without the worker (the app, and phones over CalDAV). Reminders, subscribed calendars and Google refreshing, email import, prompt filters, place pins and plugins need it, and say so where you turn them on. If you remove it on purpose, Settings, System lets you mark it as turned off.

6. **Check it.** `https://cal.example.com/api/v1/health` should show `{"ok":true,"db":true,...}`. Then open `https://cal.example.com`, sign in, and the welcome walks you through the first settings. A couple of minutes later, Settings, System should show the worker's jobs as healthy.

That's a working install. The rest is optional:

- **Push reminders** are set up by the installer; each person turns them on per device ([the app and reminders](app-and-reminders.md)).
- **Google Calendar** needs an OAuth client of your own, from a free Google Cloud project, in about ten minutes ([google-calendar.md](google-calendar.md#one-time-setup-the-operator)).
- **Email** for reminders by mail and invitation replies: the SMTP settings in `.env.php`.
- More under [After installing](#after-installing).

If something here didn't work as written on your host, please [report it](https://github.com/Oshyan/better-cal/issues).

### Install Without a Command Line

For hosting with a control panel and a file manager (or FTP) but no SSH: a setup page in the browser does what the installer does. Uploading doesn't check the release's signature; you can [check the zip on your own computer first](#optional-verification-before-upload).

1. **Upload the release zip** (`better-cal-X.Y.Z.zip` from the [releases page](https://github.com/Oshyan/better-cal/releases)) with your host's file manager and extract it into a folder named `better-cal`. Put it outside your web root if the host allows (beside `public_html`); if subdomains must point inside `public_html`, make it `public_html/better-cal`. If the panel already made that folder when you set up the subdomain, extract into it with overwrite on.
2. **Create a database and a user** on the control panel's database page. Note the host, database name, user and password.
3. **Point a domain or subdomain of its own at `better-cal/server/public`** ([why](#choosing-where-to-put-it)) in the control panel's domain settings, and turn on HTTPS for it (most panels have a Let's Encrypt switch).
4. **Open the address in your browser.** The setup page asks for a code it has just written into `better-cal-code.php` in the `better-cal` folder: open that file in the file manager and type the code in. (It proves you can reach the server's files.)
5. **Fill in the form:** the address, the database details (checked before anything is saved) and your account. Setup creates the tables, saves `.env.php` (readable only by your account) and makes your account, and the setup page is then gone for good. If its check of your main domain finds a problem, the last page says so.
6. **Add the cron job** it shows you, on the control panel's cron or scheduled-tasks page: set every schedule field to every (`*`), or pick "once per minute", and paste the command. If the panel has a "PHP" job type that fills in the start of the path, choose its "custom" type instead and paste the whole command, since that names the PHP version your site runs on. Then sign in.

PHP needs to be able to write to the `better-cal` folder, which on shared hosting it normally can; if it can't, the page says so.

### From Git Instead of the Zip

For following development or making changes: `git clone https://github.com/Oshyan/better-cal.git /srv/better-cal`, `git checkout` the newest release tag, then install the PHP libraries with [Composer](https://getcomposer.org/download/) (`cd server && composer install --no-dev`) before running the installer. Updating works from a checkout too.

### By Hand, Without the Installer

1. `cp .env.example .env.php && chmod 600 .env.php` (keep its first line), then fill in `BETTERCAL_BASE_URL`, `BETTERCAL_DB_DSN`, `BETTERCAL_DB_USER`, `BETTERCAL_DB_PASS` and `BETTERCAL_SESSION_SECRET` (`openssl rand -hex 32` makes one). Everything else in the file is optional and explained where it appears.
2. `php server/bin/migrate.php` creates the tables.
3. `php server/bin/seed.php --email=you@example.com` creates your account; it asks for the password without showing it. From a script, set `BETTERCAL_SEED_PASSWORD` instead; never put a password on the command line, where other users and the shell history can see it.
4. Optional, for push reminders: `php server/bin/vapid.php --generate`, and put the keys it prints into `.env.php`.

## Requirements in Detail

- PHP 8.4.1 or newer with `pdo_mysql`, `curl`, `mbstring`, `xml` (dom, simplexml, xmlreader, xmlwriter: CalDAV needs them) and `zip`, plus `json`, `openssl`, `sodium`, `ctype`, `iconv` and `fileinfo`, which nearly every PHP build already has. `composer check-platform-reqs --lock --no-dev`, run in `server/`, lists anything missing.
  - Best with `proc_open` allowed and the PHP command-line binary installed (`php8.4-cli` below), so outbound requests look up addresses in a child process with a time limit. Without them (some shared hosts disable `proc_open`) everything still works and private addresses are still refused, but a stuck DNS server can hold a request until the system resolver gives up; the PHP error log says so once.
  - Shared hosting: pick PHP 8.4 or 8.5 in the host's panel.
  - Debian 13 and Ubuntu 26.04 ship PHP 8.4 and 8.5 respectively: `apt install php8.4-cli php8.4-mysql php8.4-curl php8.4-mbstring php8.4-xml php8.4-zip` (use `8.5` on Ubuntu 26.04), plus `libapache2-mod-php8.4` or `php8.4-fpm` for the web server.
  - Ubuntu 24.04 and Debian 12 ship older PHP. Add the widely used packages by Ondřej Surý first (`sudo add-apt-repository ppa:ondrej/php` on Ubuntu; for Debian, https://packages.sury.org/php/), then the `php8.4-*` packages above.
- MySQL 8 or MariaDB 10.6 or newer, with one empty database and a user with full rights on it. Tested on MySQL 8.4 (Percona Server) and MariaDB 10.6 and 10.11. MySQL 8.0 should work but is past end of life and untested.
- A cron entry (or any scheduler) that can run a PHP script every minute, and HTTPS.
- Composer only to install from git; Node 20+ only for the frontend tests, `scripts/deploy.sh` or the MCP server. The app itself needs no build step.
- Keeping PHP, the database and the web server updated is up to you or your host ([SECURITY.md](../SECURITY.md#scope-notes)).

### Where It Has Been Tested

- **Day-to-day use:** Nginx with PHP-FPM 8.4 and MySQL 8.4, on Linux.
- **Clean installs** following this page from an empty machine: Ubuntu 24.04 with Apache 2.4, mod_php and MariaDB 10.11 (and 10.6), checking install, migrations, sign-in, events and undo, search, repeating events, CalDAV and API tokens through Apache, a subscribed feed, a plugin and the worker under cron; repeated with PHP 8.4 from the PPA above and only the listed extensions. GitHub's tests also install on PHP 8.4 on every push.
- **Shared hosting:** LiteSpeed with PHP chosen in the control panel and the app folder inside `public_html` (Hostinger), installed from the release zip with the setup page and a control-panel cron job, then updated by extracting a newer zip over it. And DreamHost shared hosting, installed by someone other than the maintainer following this page.

## Updating

Settings, About shows the running version, and the app tells you when a release is out (it checks GitHub once a day; Settings, About can set notices to **All releases**, **Security updates only** or **Off**, and **Check now** is always there). The app never downloads or installs anything itself. With SSH, updating is one command; without it, a few steps in your host's file manager and one button. A one-click update from the app is planned ([#139](https://github.com/Oshyan/better-cal/issues/139)).

Each [release page](https://github.com/Oshyan/better-cal/releases) ends with an **Operators** line: what the update needs from you, such as "no migration", a new setting, or a newer PHP. Skipping versions is fine, since the migration applies everything in between, but read the Operators lines of the ones you skip too.

**Before updating, open Settings, System.** If it says the session secret is missing, add the `BETTERCAL_SESSION_SECRET=` line it shows to `.env.php` (replacing an empty line with that name if there is one, and keeping the first guard line), then reload; from a command line, `php server/bin/settings.php --new-secret-line` prints one. Updates can't make a backup without it, so they stop before changing anything. If an earlier secret was lost, recover it instead of making a new one: older encrypted backups and saved service credentials need the original ([Backups and exports](../SECURITY.md#backups-and-exports)).

### With the Updater

As the account that owns the files:

```sh
php /srv/better-cal/server/bin/update.php
```

It finds the newest release, checks it (a published, unchangeable GitHub release with a valid manifest, signed by a key this copy of Better-Cal trusts; [releasing.md](releasing.md#signing)), and shows its Operators line. If you say yes, it:

1. **puts the site in maintenance mode**, so it shows "updating" for a minute and the worker pauses;
2. **backs up the database** into `/srv/better-cal-backups/`, encrypted, written by PHP itself (no `mysqldump` needed);
3. **installs the new version.** From a release zip, it downloads the zip, checks it against GitHub's SHA-256 and unpacks it beside the current copy, carrying over your settings, plugins you added, per-host files like `.user.ini`, and lines you or your control panel added to its `.htaccess` files. Then it swaps the two folders, so the previous version stays whole in `/srv/better-cal-<old version>`. From a git checkout, it checks out the release tag and runs Composer. If the release changes which keys may sign the next one, it says so;
4. **runs the database changes**, names any shipped files that had been edited on the server (your copies stay in the previous version's folder), brings your private files up to date (settings still in an old `.env` move to `.env.php`, older backups get encrypted), and brings the site back.

`--check` only says whether there's a release; `--yes` skips the question. One update runs at a time. It needs to be able to create folders beside the app directory (`/srv` here), which on shared hosting your home directory allows. Open browsers and the installed app pick up the new version on their next load.

**If something goes wrong,** the updater stops and says so. Before the new code is in place, it puts the site back on the current version. After that, it leaves the site in maintenance mode, and

```sh
php /srv/better-cal/server/bin/update.php --rollback
```

puts the previous version and the database back as they were. It also tidies up after an update that was cut off partway (a closed terminal, a killed process) and works after an update that finished but misbehaves; do it soon, since anything entered since the backup is lost. Going back to a version before 0.9.83, which only reads `.env`, it writes `.env` for it. If a restore stops partway, the site stays closed: run `--rollback` again (if the worker is still finishing a job, nothing is restored yet; retry when it's done). Don't remove the maintenance file to get around a failed restore. Please [report what happened](https://github.com/Oshyan/better-cal/issues).

The updater keeps the newest two previous versions and database backups and removes older ones (each copy is a whole app, and shared hosts limit how many files an account may have). Copies you made yourself, under any other name, are never touched; Settings, System lists them under Private files. Keep a copy of `.env.php` with any backup you copy elsewhere, since the backup's key comes from it. `php server/bin/backup.php --decrypt FILE OUT.sql.gz` turns a backup into a plain `.sql.gz` for phpMyAdmin or the `mysql` client.

### Without a Command Line

These steps don't check the release's signature; you can [check the zip on your own computer first](#optional-verification-before-upload), and otherwise download only from the [releases page](https://github.com/Oshyan/better-cal/releases).

1. **Upload the new release zip** with your host's file manager next to the current `better-cal` folder (not into it), and extract it into a new folder named `better-cal-new`.
2. **Copy your own files across** from `better-cal` into `better-cal-new`: `.env.php` (or `.env`, on a version before 0.9.83) and any plugins you added under `server/plugins/`. Lines of your own in an `.htaccess` file go above its `# BEGIN Better-Cal` line in the new copy. Names starting with a dot are hidden in some file managers; look for a "show hidden files" setting.
3. **Swap the folders by renaming them:** `better-cal` to `better-cal-old`, then `better-cal-new` to `better-cal`. Your domain and cron job keep pointing at the same place. If you picked a PHP version in your control panel, pick it again now, since the panel keeps that choice in `server/public/.htaccess` (if you forget, the site says so).
4. **Open Better-Cal.** If the new version has database changes, you'll see "Database update needed" instead of your calendar (the installed app shows the same, with a button to the update page). Enter the code it writes into `better-cal-code.php`, as at setup, and press **Back up and update the database**: it backs up first, beside the `better-cal` folder (or inside it, if the folder above isn't writable), then applies the changes. With no changes, Better-Cal simply opens on the new version.

If the database update fails, the page offers **Restore the database as it was**; then rename the folders back. If the host cuts the page off partway (some end requests after 30 or 60 seconds), Better-Cal shows the same page again, to run it again or restore; if a restore stops partway, use **Run the restore again**. To go back after an update that worked, rename the folders back and open Better-Cal: it sees that the database is newer than its files and offers to restore the backup made before the update (versions before 0.9.86 can't; use your host's own backups). Turning a backup into a file phpMyAdmin can import takes a command line (`php server/bin/backup.php --decrypt`). Keep `better-cal-old` until you're happy with the new version.

### By Hand, With SSH

The updater's steps yourself, as the user that owns the files, in the app directory (`/srv/better-cal` here). These skip the signature checks: a zip can be [checked on your own computer first](#optional-verification-before-upload), but a `git checkout` followed by Composer isn't checked against a signature at all.

1. **Back up the database**, and keep a copy of your settings (your host's own database backup works too, if you can restore it):

   ```sh
   mysqldump --single-transaction -u DB_USER -p DB_NAME > ~/bettercal-before-update.sql
   cp .env.php ~/bettercal-settings-backup.php
   ```

2. **Get the new code.**
   - From a git clone: `git fetch --tags`, `git checkout vX.Y.Z`, then `cd server && composer install --no-dev && cd ..`. Releases are tags; `main` can be ahead of the newest release.
   - From a release zip: unpack it into a fresh directory beside the current one (`unzip better-cal-X.Y.Z.zip -d /srv/better-cal-new`), copy `.env.php` and any plugins you added under `server/plugins/` into it, then swap: `mv /srv/better-cal /srv/better-cal-old && mv /srv/better-cal-new /srv/better-cal`.
3. **Apply database changes:** `php server/bin/migrate.php`. It lists each change it applies, or none.
4. **Check it:** `https://cal.example.com/api/v1/health` should say `{"ok":true,"db":true,...}`, and Settings, About should show the new version.

Between steps 2 and 3, the new code runs against the old database for a few seconds. To avoid that, create an empty file named `.maintenance` in the app directory before step 2 (the site shows "updating" and the worker pauses) and delete it after step 3.

If the migration stops with an error, don't keep using the app: restore the backup (`mysql -u DB_USER -p DB_NAME < ~/bettercal-before-update.sql`), go back to the previous code (`git checkout` the old tag and run Composer, or swap `/srv/better-cal-old` back), and please [report the error](https://github.com/Oshyan/better-cal/issues). If the health check fails, the PHP error log usually says why; most often it's a missing PHP extension or version a release's Operators line asked for.

### Optional Verification Before Upload

For proof that a download really is the signed release. It runs on your own computer, which needs PHP; nothing changes on your host.

The check has to come from a copy of Better-Cal you already trust, such as your existing install (copied to your computer before you download the update). From that copy's folder:

```sh
php scripts/release-sign.php --verify=/path/to/bettercal-release.sig --version=X.Y.Z --zip=/path/to/better-cal-X.Y.Z.zip --manifest=/path/to/bettercal-release.json
```

Download all three files from the same release page. Go ahead only if it says the release verified, and upload the exact zip you checked. Don't use the checker from inside the new zip: a tampered zip could carry a checker that says yes. A checksum published next to the zip doesn't help either, since anyone who could swap the zip could swap it too. For a first install you have no earlier copy to check with, so you're trusting the download.

### Recovering a Setup or Database-Update Session

A code works once, and is good for an hour once entered; wrong codes never change it or count against you. If the page says the code was already used and your browser lost its place (a closed private window, a crash, a different device), delete `better-cal-code.php` with your host's file manager and reload: a new code appears. The same fixes the rare host without file locking, where two requests at once can trip over each other. If you update Better-Cal while one of these pages is open, you may need to read a new code; your normal sign-in isn't affected.

The maintainer's own deploy tools (`scripts/deploy.sh` and friends) aren't needed to run Better-Cal; [deploying.md](deploying.md) describes them.

## Dependencies and Advisories

The PHP libraries are pinned in `server/composer.lock` and checked with `composer audit --no-dev --locked` on every push and deploy. The browser libraries are committed under `web/vendor/`, pinned by SHA-256 in `web/vendor/manifest.json`, and listed in [web/vendor/README.md](../web/vendor/README.md); `node scripts/vendor.mjs --verify` checks the tree against the pins, and `--check-upstream` against the published releases. Updates are reviewed, never automatic ([dependency policy](dependencies.md)).

## How Requests Are Routed

This is all a web server needs to know, on any of them:

- The document root is `server/public`.
- Requests whose path starts with `/dav` go to `server/public/dav.php` (CalDAV).
- **Every other request goes to `server/public/index.php`**, whatever the path: the API under `/api/v1`, public feeds under `/feed/`, static files under `/assets/`, `/sw.js`, the manifest and favicons, and the app's HTML for any other path (`/add`, `/subscribe`, `/share` and so on).
- The app reads the original path from `REQUEST_URI`, so the server must rewrite internally, not redirect.
- The `Authorization` header must reach PHP: API tokens and CalDAV logins both arrive in it.

The app sets its own cache headers, so there's nothing to configure: `/assets/vendor/` is cached for 30 days as immutable, and everything else (including `sw.js` and the HTML) is `no-cache` with an ETag, so browsers revalidate and get a 304 unless a deploy changed the file. If you override this, **never let `sw.js` be cached without revalidation**, or browsers won't notice new versions.

`server/public/assets` is a symlink into `web/` so a web server can serve those files directly; routing everything to `index.php` works without it. But **`/sw.js` must always go to `index.php`**: the app fills in the service worker's version as it serves it, and served straight from disk the app loses offline start (it still works online).

## Nginx (Tested)

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

Optional: let Nginx serve the static files itself, mirroring the app's cache policy, above `location /`:

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

The `types` block lists every extension the frontend uses because `.webmanifest` is missing from Nginx's defaults and a `types` block replaces the inherited map. Don't add a rule that serves `/sw.js` from disk.

**Hosting panels (CloudPanel and many others) add a catch-all rule for static file extensions**, typically `location ~* \.(css|js|png|...)$` with `expires max`, which catches `/sw.js` before your `location /` does. Add an exact rule that sends it to the app (an exact `location =` always wins over a regex). With PHP-FPM directly:

```nginx
    location = /sw.js {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
```

Behind a panel that proxies `location /` to an inner server (CloudPanel does: `proxy_pass http://127.0.0.1:8080`), use the same `proxy_pass` and headers as its `location /` inside `location = /sw.js { ... }`. To check: `curl -sI https://cal.example.com/sw.js` should show `ETag: W/"bc-` followed by twelve hex digits.

## Apache 2.4 (Tested With mod_php)

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

Use `[END]`, not `[L]`: in a directory context `[L]` starts another rewrite pass, which would send `dav.php` on to `index.php`. For a plain-HTTP test on your own machine, use `<VirtualHost *:80>` and an `http://localhost:PORT` base URL; browsers treat localhost as secure, so sign-in works without a certificate.

**On shared hosting, or anywhere you can't edit the virtual host, there's nothing to add:** `server/public/.htaccess` ships with the same routing and a way of passing the Authorization header that works where `CGIPassAuth` isn't allowed. The host only has to allow `FileInfo` overrides for that directory, which shared hosts do; LiteSpeed reads it too. With `AllowOverride None`, as above, the file is ignored.

If the host forces the document root to a directory you don't control (`public_html`), make that directory a symlink to `server/public`, or put the app beside it and symlink `index.php` and `dav.php` in; both find the rest of the app from their real path.

If signing in through the browser works but API tokens return 401 and CalDAV clients keep asking for the password, the `Authorization` header is being dropped.

## Behind a Proxy or CDN

Sign-in attempts are rate limited per network address. If something on another address sits in front of the web server (Cloudflare, a load balancer, a separate reverse-proxy host, a container ingress), PHP sees that proxy's address for every visitor, so they'd all share one limit. Set `BETTERCAL_TRUSTED_PROXIES` in `.env.php` to the proxy's IPs or CIDR ranges, and the real address is read from the right-hand end of `X-Forwarded-For`, the part a client can't forge. Otherwise leave it empty and the header is ignored. Nginx or Apache on the same machine handing requests to PHP-FPM isn't a proxy in this sense.

Better-Cal answers only at the host in `BETTERCAL_BASE_URL` ([why](#choosing-where-to-put-it)): any other host is redirected there, or refused with 421. If a proxy rewrites the `Host` header to an internal name, or the install is deliberately reached by a second name, list those in `BETTERCAL_ALLOWED_HOSTS`, comma separated.

**Check that your web server isn't already believing visitors about their address.** Nginx's `real_ip` module (`set_real_ip_from`, `real_ip_header`) rewrites the client address from a header, and should only do so for peers you control. CloudPanel's `/etc/nginx/nginx.conf` (seen in CloudPanel 2.5) has `set_real_ip_from 0.0.0.0/0;` with the default `X-Real-IP` header, which trusts that header from everyone: any visitor can claim any address, dodging the per-address limit and aiming blocks at someone else. Keep the loopback and private ranges (CloudPanel's inner server on port 8080 needs `127.0.0.1`) and remove the `0.0.0.0/0` line, or replace it with your CDN's published ranges. To test, send ten wrong passwords with a made-up `X-Real-IP` header and see which address the block in Activity names (or look at `rate_events`).

## Keeping Private Files Private

A few of Better-Cal's files must never be downloadable: the settings file (database password, session secret, keys), database backups, the one-time setup code and PHP's error log. They're protected wherever the folder sits ([SECURITY.md](../SECURITY.md#releases-and-updates) has the details):

- **The settings file starts with a guard line,** `<?php __halt_compiler();`, so a server asked for it runs it as PHP and shows a blank page. Keep that first line when you edit the file. If it's deleted, Better-Cal puts it back; if something else ends up in front of it, Better-Cal refuses to start and says what to fix.
- **An old `.env`** (from before 0.9.83) still works until it's converted. The updater, the database update page and the worker convert it when it belongs to the account they run as; `php server/bin/settings.php --convert` does it by hand, run as the account that owns `.env` (`sudo` for a root-owned one, which keeps its owner, group and mode).
- **Backups are encrypted** with a key made from `BETTERCAL_SESSION_SECRET`, and end in `.bcbackup`. That protects a backup only while the settings file stays private, and restoring needs the settings it was made with, so keep a copy of `.env.php` with any backup you store elsewhere, and guard the two equally. `php server/bin/backup.php --decrypt FILE OUT.sql.gz` writes a plain copy for other tools; delete it when you're done.
- **The setup code, the update record and PHP's error log are guarded the same way.** Where a host sets PHP to write errors beside the script (a bare `error_log`, common on shared hosting), Better-Cal sends them to `.runtime-cache/php-errors.php` instead.
- **A `.htaccess`** at the top of the folder refuses every request on servers that read one.

**Settings, System, Private files** says whether this holds on your host. Better-Cal writes a test file into its folder and asks your main domain for it (`example.com` and `www.example.com` for `cal.example.com`), where that domain would serve the folder if it sits in its web space:

- **Safe:** the folder is in the main domain's web space but the server refuses every request for it, or the main domain reaches it but the settings file shows as a blank page and the backups are encrypted.
- **Probably safe:** no address it tried serves the folder. That's the most it can say for a folder outside the web root or Better-Cal on a domain of its own, since it can't rule out an address it doesn't know about; it says how to check that one yourself.
- **Fix this now:** the settings file can be read from the web (the server hands out PHP files as text in that folder, or an unconverted `.env` sits there). Move the folder out of the web root, ask your host to run PHP there, or convert `.env`. Then change what the file holds, since it may already have been copied: the database password first, then any mail, Google or other service passwords and keys. A new `BETTERCAL_SESSION_SECRET` signs everyone out, means connecting Google again and makes older backups unreadable, so change it, take a fresh backup, and delete the old ones.
- **Not checked:** your host doesn't let a site fetch pages from its own websites, which is common. The message says how to check by hand.

The check runs at the end of setup and of each update, and when you press **Check now** (at most once a minute). Notes under the result point out leftovers worth deleting, like an old `worker.log`.

**File permissions.** Keep `.env.php` readable by the PHP user and nobody else: on shared hosting, `chmod 600 .env.php`, owned by your own account (which PHP runs as). The backup folder and its files must belong to the account running Better-Cal and must not be links; unsafe paths are refused rather than repaired, and Settings, System reports storage that needs attention. Convert older backups as that account, not as root.

**Exports.** Download .ics streams a calendar directly. Export everything builds the whole zip first in `.runtime-cache/exports` (owner-only files, deleted after sending), so it needs disk space for the calendar files and the zip together, and your host may still end a long request; retry a failed download. One export runs at a time. The export files aren't encrypted, so keep `.runtime-cache` out of public web space or deny access to it ([Backups and exports](../SECURITY.md#backups-and-exports)).

## After Installing

- `php server/bin/smoke.php` checks that the events window, a single event and the calendar list serialize against your real data.
- To look around before moving anything in, `php server/bin/demo-seed.php` fills an empty account with invented calendars, events and people around today. It refuses to run once the account has events of its own.
- CalDAV clients connect to `https://cal.example.com/dav/` with your email and either your password or, better, an API token (`php server/bin/token.php --create --name="My phone"`). See [caldav.md](caldav.md).
- Place search works out of the box on Photon (free, no key). Free LocationIQ and MapTiler keys make it much faster: [Geocoding and place search](geocoding.md) has the setup, the Stadia Maps storage caution and the comparison.
- Set your Home time zone under Settings, General. The screen always follows the device you're using; Home is the clock all-day reminders fire on and the zone of events created for you by email, plugins and the API.

## Removing Better-Cal

If you might want your calendars again, keep a copy first: **Settings, Account, Export everything** downloads a zip with one `.ics` file per calendar, which Better-Cal imports in one step and other calendar apps a file at a time. A database backup together with `.env.php` (needed to read it) keeps everything else too: people, labels, filters and settings.

1. **The cron job.** Delete it first (control panel, or `crontab -e`), so nothing runs while the rest goes.
2. **The address.** Remove the subdomain or domain you pointed at `server/public`, or its web server configuration, and its HTTPS certificate if it has one of its own.
3. **The files.** Delete the `better-cal` folder (its settings, setup code and logs are inside), plus anything beside it Better-Cal made: `better-cal-backups` and any `better-cal-<version>` folders kept by updates.
4. **The database.** Delete the database and its user (control panel, or `DROP DATABASE` and `DROP USER` in MySQL).
5. **Your devices.** Remove the home-screen app where you installed it, and clear the site's data in each browser you used (its offline copy and notification sign-up). Remove the account from calendar apps connected over CalDAV.
6. **Google, if you connected it.** Remove Better-Cal's access in your Google Account (Security, Third-party apps and services), and the OAuth client in your Google Cloud project if you made one only for this.
