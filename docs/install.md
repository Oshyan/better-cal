# Installing Better-Cal

Better-Cal is plain PHP and MySQL with a no-build frontend. It runs on any host that gives you PHP 8.4+, MySQL 8 or MariaDB 10.6+, a cron job and a web server you can point at one directory.

What has been run, and how:

- **Production:** Nginx with PHP-FPM 8.4 and Percona Server 8.4 (MySQL), on Debian.
- **Clean-install test (2026-10-03):** Ubuntu 24.04 with Apache 2.4 and mod_php 8.3 (the minimum then; it is 8.4 since 0.9.0, and the GitHub check installs on 8.4 on every push), MariaDB 10.11 (and MariaDB 10.6 for the migrations, the smoke test and the worker), following this page from an empty machine. Everything below was checked there: install, migrations, sign-in, events and undo, search, repeating events, CalDAV and API tokens through Apache, a subscribed feed, a plugin, the worker under cron.
- **Repeated on 2026-10-05** with PHP 8.4 from the Ubuntu PPA described below (mod_php, only the listed extensions) and MariaDB 10.11: install, migrations, the worker under cron, and the first sign-in to a new account with its welcome.

If your host differs and something needs changing, please report it.

## Requirements

- PHP 8.4 or newer (8.4.1 or later) with these extensions: `pdo_mysql`, `curl`, `mbstring`, `xml` (dom, simplexml, xmlreader, xmlwriter: CalDAV needs them), `zip`, plus `json`, `openssl`, `sodium`, `ctype`, `iconv` and `fileinfo`, which nearly every PHP build already has. `composer check-platform-reqs --lock --no-dev`, run in `server/`, lists anything missing.
  - Best with `proc_open` allowed and the PHP command-line binary installed in PHP's own bin directory (`php8.4-cli` below does this on Debian and Ubuntu). Every outbound request (feeds, Google, place search, plugins) then looks up its address in a short-lived child process with a time limit, so a slow DNS server cannot stall it. Where either is missing (some shared hosts disable `proc_open`), the lookup runs in-process instead and the PHP error log says so once: everything still works and private addresses are still refused, but a stuck DNS server can hold a request until the system resolver gives up. Some shared hosts disable `proc_open`. Outbound requests connect directly: proxy variables such as `HTTPS_PROXY` are ignored.
  - Shared hosting: pick PHP 8.4 or 8.5 in the host's panel (DreamHost offers both as of October 2026; elsewhere, check the host's PHP version picker).
  - Debian 13 and Ubuntu 26.04 ship PHP 8.4 and 8.5 respectively: `apt install php8.4-cli php8.4-mysql php8.4-curl php8.4-mbstring php8.4-xml php8.4-zip` (use `8.5` on Ubuntu 26.04), plus `libapache2-mod-php8.4` or `php8.4-fpm` for the web server.
  - Ubuntu 24.04 and Debian 12 ship older PHP. Add the widely used packages by Ondřej Surý first: `sudo add-apt-repository ppa:ondrej/php` on Ubuntu (Debian: https://packages.sury.org/php/), then the `php8.4-*` packages above.
- Composer, to install the PHP dependencies.
- MySQL 8.4 or MariaDB 10.6 or newer, with one empty database and a user with full rights on it. Tested on MySQL 8.4 (Percona Server) and MariaDB 10.6 and 10.11. MySQL 8.0 should work, since nothing here needs newer SQL, but it is past end of life and untested.
- A cron entry (or any scheduler) that can run a PHP script every minute.
- HTTPS. Sessions, the service worker and Web Push all require it.
- Node 20+ only if you want to run the frontend tests, `scripts/deploy.sh` (which runs them) or the MCP server. The app itself needs no build step.

## Steps

1. Put the repository somewhere outside the web root, for example `/srv/better-cal`.
2. `cd server && composer install --no-dev`
3. Copy `.env.example` to `.env` in the repository root and fill in the database, `BETTERCAL_BASE_URL` and `BETTERCAL_SESSION_SECRET`. Everything else in that file is optional and documented there.
4. `php server/bin/migrate.php` creates the schema. Run it again after every update; it only applies what is new.
5. `php server/bin/seed.php --email=you@example.com` creates your account; it asks for the password with echo off. From a script, set `BETTERCAL_SEED_PASSWORD` instead; a password on the command line is visible to other users and stays in shell history.
6. Point the web server at `server/public` (next section).
7. Add the worker to cron, as the user that owns the files: `* * * * * php /srv/better-cal/server/bin/worker.php >> /srv/better-cal/worker.log 2>&1`. It polls feeds, ingests mail, sends reminders and prunes old data. Settings, System shows whether each job is healthy.
8. Optional: `php server/bin/vapid.php --generate` and put the keys in `.env` to enable push reminders.
9. Optional: the Google Calendar connector needs an OAuth client of your own, from a free Google Cloud project: about ten minutes, with direct links to each console page, in [google-calendar.md](google-calendar.md#one-time-setup-the-operator).

Updating is: pull, `composer install --no-dev`, `php server/bin/migrate.php`. `scripts/deploy.sh` does this over rsync and ssh. It ships the last commit, not the working tree, so commit first: uncommitted edits are left out. It runs the server, smoke and static suites, deploy-security checks and the vendor check on exactly those files first and refuses to ship a red tree. It then backs up the app directory and MySQL/MariaDB database before changing anything, into the root-owned `BACKUP_DIR` (`/home/bettercal-backups` by default), and finishes with the health check and `smoke.php`. The application account reads the backup sources but cannot write the private destination. To use it, copy `scripts/deploy.env.example` to `scripts/deploy.env`, fill in the host and paths, and run it. It assumes a Debian-style host with sudo, composer, mysqldump, `flock` and cron; `scripts/deploy-dev.sh` ships any branch to a second, isolated install on the same host. SQLite remains useful for tests, but this production deploy helper deliberately refuses an SQLite DSN; use SQLite's own online-backup mechanism separately.

### Development deployments

`scripts/deploy-dev.sh` deploys the working tree to a separately configured dev directory and database. Provision `DEV_DIR`, `DEV_DB`, the dev `.env` and its distinct `BETTERCAL_SESSION_SECRET` before using it. Database accounts and grants are operator-owned configuration: the deploy verifies them but never creates, locks, unlocks or rewrites them.

Three flags in the ignored `scripts/deploy.env` control the security/usability tradeoffs; `scripts/deploy.env.example` contains the complete configuration:

- `DEV_ISOLATION_MODE=strict|shared` defaults to `strict`. Strict mode requires separate Unix, database and PHP-FPM identities and verifies that the two application identities cannot read one another's database or `.env`. Explicit `shared` mode permits those identities and credentials to be reused and prints a warning on every deploy. The application directories, database names and session secrets must still differ. A shared PHP-FPM service is never stopped or restarted; if opcode timestamp checks are disabled, changed dev code may remain stale until an operator-chosen reload.
- `DEV_EXTERNAL_SERVICES=disabled|enabled` defaults to `disabled`. Enabling it permits integrations deliberately configured in the dev `.env` and warns that mail, push, AI, maps and connected calendars can have real side effects or cost. A clone never copies production Google connections or active plugin settings: reconnect or reconfigure those in dev. Receive-only ICS subscriptions are separate from this switch.
- `DEV_CLONE_SUBSCRIPTIONS=preserve|strip` defaults to `preserve`. A `CLONE_DB=1` clone re-encrypts ordinary ICS source addresses with the dev session secret so they keep synchronizing. `strip` removes the synchronization addresses and leaves their imported events as local snapshots.

Every `CLONE_DB=1` operation first imports production into a private, randomly named staging schema, proves neither application identity can access it, sanitizes and verifies it there, and only then replaces the dev database. Copied sessions, trusted devices, API tokens, push subscriptions, outbound feeds, Google connections, active jobs and plugin integration configuration are removed. Plugins and Google-backed calendars remain as inert data snapshots; enable and configure them again in dev when that is the behavior being tested. All copied account passwords are replaced with `DEV_LOGIN_PASSWORD`, which belongs only in the ignored deployment configuration.

## Dependencies and advisories

PHP dependencies are Composer's and pinned in `server/composer.lock`; `composer audit --no-dev --locked` (run from `server/`) checks them against the Packagist advisory database, and the deploy script runs it after every install as a report. The frontend has no package manager: its libraries (Preact, htm, Squire, DOMPurify, Leaflet) are committed under `web/vendor/` and pinned in `web/vendor/manifest.json` with their npm package, version and the sha256 of the shipped file. `node scripts/vendor.mjs --verify` confirms the tree matches the manifest (the deploy script runs this too); to upgrade one, change its version in the manifest, run `node scripts/vendor.mjs --fetch <file>`, review the diff and commit both.

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

Sign-in attempts are rate limited per network address. If something on another address sits in front of the web server (Cloudflare, a load balancer, a separate reverse-proxy host, a container ingress), PHP sees that proxy's address for every visitor, so they would all share one limit. Set `BETTERCAL_TRUSTED_PROXIES` in `.env` to the proxy's IPs or CIDR ranges and the real address is read from `X-Forwarded-For`, from the right-hand end, which is the part a client cannot forge. Leave it empty otherwise: by default the header is ignored. Nginx or Apache on the same machine handing requests to PHP-FPM is not a proxy in this sense and needs nothing.

**Check that your web server is not already believing visitors about their address.** The app can only be as right as the address the web server hands it. Nginx's `real_ip` module (`set_real_ip_from`, `real_ip_header`) rewrites the client address from a header, and it should only do so for peers you control. CloudPanel's `/etc/nginx/nginx.conf` (seen in CloudPanel 2.5) includes `set_real_ip_from 0.0.0.0/0;` and leaves the header at nginx's default, `X-Real-IP`, which trusts that header from everyone: any visitor can then send `X-Real-IP: 1.2.3.4` and be counted as that address, which lets them dodge the per-address limit and aim a block at someone else's address. Keep the loopback and private ranges (CloudPanel's inner server on port 8080 needs `127.0.0.1`) and remove the `0.0.0.0/0` line, or replace it with your CDN's published ranges if you use one. To test: send one wrong password with a made-up `X-Real-IP` header and see which address the block in Activity names after ten of them, or look at `rate_events`.

## Protecting `.env`

`.env` holds the database password and the session secret. Keep it readable by the PHP user and by nobody else: on shared hosting (Dreamhost, Bluehost and the like) that is `chmod 600 .env`, owned by your own account, which is also the user PHP runs as. For the root-backed deploy script, provision it once as `root:APP_USER` mode `0640` (for example, `chown root:bettercal .env && chmod 640 .env`). The deploy validates that it is a readable regular file and refuses a symlink, but deliberately does not change its ownership or mode through the app-owned directory. Normal code deployments leave it untouched; repeat those two provisioning commands only after deliberately replacing `.env`.

Production backups use a separate privilege boundary. `BACKUP_DIR` must be one direct child of `/home` or `/var/backups`; the deploy creates it as `root:root` mode `0700`, creates each archive as mode `0600`, and keeps the newest five app and database copies. The app user supplies the readable source stream but cannot create, replace or remove backup files. Installations that used an older deploy script may still have a legacy `backups` directory beside the app. After confirming a new backup exists under `BACKUP_DIR`, archive or remove that legacy directory as a separate, deliberate maintenance step.

## After installing

- `https://your-host/api/v1/health` should return `{"ok":true,"db":true,...}`.
- `php server/bin/smoke.php` checks that the events window, a single event and the calendar list serialize against your real data.
- To look around before moving anything in, `php server/bin/demo-seed.php` fills an empty account with invented sample calendars, events and people, dated around today. It refuses to run once the account has events of its own.
- CalDAV clients connect to `https://your-host/dav/` with your email and either your password or an API token (`php server/bin/token.php --create --name="My phone"`). See [caldav.md](caldav.md).
- Set your Home time zone under Settings, General. The screen always follows the device you are using; Home is the clock all-day reminders fire on and the zone of events created for you by email, plugins and the API.
