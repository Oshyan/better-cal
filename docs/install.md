# Installing Better-Cal

Better-Cal is plain PHP and MySQL with a no-build frontend. It should run on any host that gives you PHP 8.3+, MySQL 8, a cron job and a web server you can point at one directory. **It has been run in production on Nginx with PHP-FPM only. The Apache configuration below follows from how the app routes requests but has not been tested on a live Apache install yet**; if you try it, please report what you had to change.

## Requirements

- PHP 8.3 or newer with `pdo_mysql`, `curl`, `json`, `openssl`, `mbstring`. Composer to install dependencies.
- MySQL 8.0.19 or newer (production runs Percona Server 8.4), one empty database and a user with full rights on it. **MariaDB is not supported yet**: a few queries use MySQL's `INSERT ... AS alias ON DUPLICATE KEY UPDATE` form, which MariaDB does not accept.
- A cron entry (or any scheduler) that can run a PHP script every minute.
- HTTPS. Sessions, the service worker and Web Push all require it.
- Node 20+ only if you want to run the frontend tests or the MCP server. The app itself needs no build step.

## Steps

1. Put the repository somewhere outside the web root, for example `/srv/better-cal`.
2. `cd server && composer install --no-dev`
3. Copy `.env.example` to `.env` in the repository root and fill in the database, `BETTERCAL_BASE_URL` and `BETTERCAL_SESSION_SECRET`. Everything else in that file is optional and documented there.
4. `php server/bin/migrate.php` creates the schema. Run it again after every update; it only applies what is new.
5. `php server/bin/seed.php --email=you@example.com` creates your account; it asks for the password with echo off. From a script, set `BETTERCAL_SEED_PASSWORD` instead; a password on the command line is visible to other users and stays in shell history.
6. Point the web server at `server/public` (next section).
7. Add the worker to cron, as the user that owns the files: `* * * * * php /srv/better-cal/server/bin/worker.php >> /srv/better-cal/worker.log 2>&1`. It polls feeds, ingests mail, sends reminders and prunes old data. Settings, System shows whether each job is healthy.
8. Optional: `php server/bin/vapid.php --generate` and put the keys in `.env` to enable push reminders.

Updating is: pull, `composer install --no-dev`, `php server/bin/migrate.php`. `scripts/deploy.sh` does this over rsync and ssh, runs every test suite first, and refuses to ship a red tree: copy `scripts/deploy.env.example` to `scripts/deploy.env`, fill in the host and paths, and run it. It assumes a Debian-style host with sudo, composer and cron; `scripts/deploy-dev.sh` ships any branch to a second, isolated install on the same host.

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

`server/public/assets` and `server/public/sw.js` are symlinks into `web/`. They exist so a web server can serve those files directly as an optimisation. You can ignore them; routing everything to `index.php` works without them.

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
    location = /sw.js {
        add_header Cache-Control "no-cache";
        try_files $uri =404;
    }
```

The `types` block is there because `.webmanifest` is missing from Nginx's default MIME map, and a `types` block replaces the inherited map inside that location, so every extension the frontend uses has to be listed. Nginx follows the symlinks by default.

## Apache 2.4 (untested)

Needs `mod_rewrite`. In the virtual host:

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

`[END]` rather than `[L]` matters: in a directory context `[L]` starts another rewrite pass, which would send `dav.php` on to `index.php`. This routes every request through PHP, including static files, so the symlinks and `FollowSymLinks` are not involved and the app's cache headers apply as they are.

On shared hosting where you cannot edit the virtual host, the same rules work as `server/public/.htaccess` (the host must allow `FileInfo` overrides). If `CGIPassAuth` is not permitted there, use the older form instead, which the app also understands:

```apache
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteRule ^dav(/|$) dav.php [END]
RewriteRule ^ index.php [END]
```

If the host forces the document root to a directory you do not control (`public_html`), make that directory a symlink to `server/public`, or put the repository beside it and symlink `index.php` and `dav.php` in; both files locate the rest of the app relative to their real path.

Signs that the `Authorization` header is being dropped: signing in through the browser works, but API tokens return 401 and CalDAV clients keep asking for the password.

## Behind a proxy or CDN

Sign-in attempts are rate limited per network address. If something on another address sits in front of the web server (Cloudflare, a load balancer, a separate reverse-proxy host, a container ingress), PHP sees that proxy's address for every visitor, so they would all share one limit. Set `BETTERCAL_TRUSTED_PROXIES` in `.env` to the proxy's IPs or CIDR ranges and the real address is read from `X-Forwarded-For`, from the right-hand end, which is the part a client cannot forge. Leave it empty otherwise: by default the header is ignored. Nginx or Apache on the same machine handing requests to PHP-FPM is not a proxy in this sense and needs nothing.

**Check that your web server is not already believing visitors about their address.** The app can only be as right as the address the web server hands it. Nginx's `real_ip` module (`set_real_ip_from`, `real_ip_header`) rewrites the client address from a header, and it should only do so for peers you control. CloudPanel's stock `/etc/nginx/nginx.conf` includes `set_real_ip_from 0.0.0.0/0;`, which trusts that header from everyone: any visitor can then send `X-Real-IP: 1.2.3.4` and be counted as that address, which lets them dodge the per-address limit and aim a block at someone else's address. Keep the loopback and private ranges (CloudPanel's inner server on port 8080 needs `127.0.0.1`) and remove the `0.0.0.0/0` line, or replace it with your CDN's published ranges if you use one. To test: send one wrong password with a made-up `X-Real-IP` header and see which address the block in Activity names after ten of them, or look at `rate_events`.

## After installing

- `https://your-host/api/v1/health` should return `{"ok":true,"db":true,...}`.
- `php server/bin/smoke.php` checks that the events window, a single event and the calendar list serialize against your real data.
- CalDAV clients connect to `https://your-host/dav/` with your email and either your password or an API token (`php server/bin/token.php --create --name="My phone"`). See [caldav.md](caldav.md).
- Set your Home time zone under Settings, General. The screen always follows the device you are using; Home is the clock all-day reminders fire on and the zone of events created for you by email, plugins and the API.
