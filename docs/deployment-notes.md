# Deployment Notes (cal.oshyan.com)

Live environment facts that are not encoded in scripts, recorded 2026-07-31.

## Hosts and paths

- Server: Hetzner box (`ssh hetzner`, root@87.99.150.92), CloudPanel, PHP 8.4, Percona MySQL 8.4.
- App root: `/home/bettercal/app` (rsynced by `scripts/deploy.sh`, no `--delete` by policy).
- Docroot: `/home/bettercal/htdocs/cal.oshyan.com` is a symlink to `app/server/public`.
- Server-only files never in the repo: `/home/bettercal/app/.env` (DB, Gemini key, session secret), `server/vendor/` (composer install runs on the server), `worker.log`.
- Cron (user bettercal): `* * * * * php /home/bettercal/app/server/bin/worker.php >> /home/bettercal/worker.log 2>&1`.
- DNS: Hetzner DNS zone oshyan.com, record `cal` A 87.99.150.92 (managed via `hcloud zone rrset`).
- TLS: Let's Encrypt via `clpctl lets-encrypt:install:certificate --domainName=cal.oshyan.com`.

## Request path and client addresses (checked 2026-09-17)

CloudPanel runs two nginx servers per site. The public one (443) proxies `location /` to an inner one on `127.0.0.1:8080`, which hands PHP requests to PHP-FPM on `127.0.0.1:19002`; `/dav` and `/assets` are served from the public one directly. PHP still sees the visitor's real address rather than `127.0.0.1`, because the public server sends `X-Real-IP` and the global `/etc/nginx/nginx.conf` applies the `real_ip` module for `127.0.0.1`. So `BETTERCAL_TRUSTED_PROXIES` stays empty here.

Fixed 2026-09-19: that same global file (shared by all 27 sites on the box) had CloudPanel's stock `set_real_ip_from 0.0.0.0/0;`, so nginx accepted an `X-Real-IP` header from any visitor; a forged header was counted as the client address by the sign-in limiter. The line was removed (backup in `/root/nginx-realip-backups/`), keeping `127.0.0.1` and the private ranges, and a forged header is now counted under the real address on both the API path (via the inner server) and the `/dav` path (served by the public server directly). Nothing on the box is behind a proxy on another address: all 11 domains delegate to Hetzner DNS and every site record points at this server, checked with `hcloud zone rrset list` and `dig NS`.

Keeping it removed: `nginx.conf` is a dpkg conffile of CloudPanel's own `nginx-common`, and `clp-update` (which pipes CloudPanel's remote installer to bash and installs through apt) could put the line back. `/usr/local/sbin/cloudpanel-config-guard` watches hand-made host configuration in two sections that both run every time: **auto-apply** items are fixed unattended (backup, fix, `nginx -t` with the original restored on failure, graceful reload), **warn-only** items are reported and never touched. It runs after every apt/dpkg operation (`/etc/apt/apt.conf.d/99-cloudpanel-config-guard`, a `DPkg::Post-Invoke` hook, with `--apply`) and weekly as a second step of the existing `cloudpanel-webmail-policy-verify` service (drop-in `config-guard.conf`). Every finding goes to syslog and `/var/log/cloudpanel-config-guard.log`; at most one email per run to the owner, and only when something changed: a fix, a new finding, or one that went away. The mail is split into new / resolved / still-present, because the first version listed only what remained and a standing warning read as a fresh problem the moment an unrelated one cleared (2026-09-19). The previous run's findings sit as lines in `/var/lib/cloudpanel-config-guard/last-findings`; `CONFIG_GUARD_SENDMAIL` can point at a script to test the mail path. Currently: auto-apply removes the catch-all `set_real_ip_from`; warn-only flags OCSP stapling coming back on (turned off by hand 2026-04-22), any other difference from the last accepted `nginx.conf` (`--accept` after a deliberate edit), and the admin-panel nginx's catch-all (standing, see below). Adding a check is a `detect_<name>` function, optionally a `fix_<name>`, and a line in one of the two lists. A snippet in `conf.d` could not have done the real-IP fix: `set_real_ip_from` is additive, and `conf.d` is not even included on this box.

Not changed: CloudPanel's separate admin-panel nginx (`/home/clp/services/nginx/nginx.conf`, port 8443) carries the same catch-all line and additionally `real_ip_header X-Forwarded-For`. It only matters if a panel-login IP allowlist is ever relied on, and that file is clearly CloudPanel-managed.

## nginx vhost customizations

The CloudPanel vhost `/etc/nginx/sites-enabled/cal.oshyan.com.conf` was hand-edited (CloudPanel may regenerate it if site settings change in the panel; re-apply if assets start 404ing or caching wrong):

```nginx
location ^~ /assets/vendor/ {
  expires 30d;
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

Rationale: nginx serves static assets directly (via the `server/public/assets` -> `../../web` symlink). App code and styles are `no-cache` (ETag revalidation, so deploys show up on next load); vendor files cache for 30 days. The service worker itself must never be cached. The `types` override exists because `.webmanifest` is missing from the default MIME map; the override replaces inherited types inside that location, so every asset extension used must be listed there.

## Cache strategy summary

Three layers cooperate: nginx headers above; the service worker (`web/sw.js`) is **cache-first for the whole app shell** (document, styles, every module, vendor), keyed to a `VERSION` that `scripts/gen-preload.mjs` derives from a content hash of every shell file, so a deploy that changes anything gets a new version and a deploy that changes nothing invalidates nothing; API GETs stay network-first. `deploy.sh` runs the generator in write mode before rsync, so the shipped worker is always self-consistent without anyone remembering to bump anything (`deploy-dev.sh` keeps `--check` as its gate). The browser re-fetches `sw.js` on every navigation (nginx: `no-cache`), installs a new version alongside the old, precaches the new shell bypassing the HTTP cache, activates, deletes the old cache, and tells open pages, which show a "Reload to get the latest" toast; until they reload they keep running the consistent set they loaded. `web/index.html`'s `?v=N` on entry assets is now only a stale-HTTP-cache escape hatch (historical: v=2 broke clients that had cached the original long-max-age headers); the worker matches shell entries ignoring the query.

## Failure alerts

Background work reports into `system_health` (one row per job type, feed, and push device). A streak lands in Activity under "System" once it has reached two consecutive failures (`JOURNAL_AFTER`), and its recovery after that; a single failed fetch shows on Settings → System and counts toward the email thresholds, but is not history. When SMTP is configured (`BETTERCAL_SMTP_HOST` / `BETTERCAL_SMTP_FROM`), a streak that lasts earns one email and one on recovery, never one per failure. Per-user subjects (a feed, a device) go to that user's account email; system-wide ones (a job type) go to `BETTERCAL_ALERT_EMAIL`, else to the first user. The alert job runs every ten minutes so a broken SMTP is one failed attempt per ten minutes. Thresholds live in `SystemHealth` (`JOB_ALERT_AFTER` 1h + 3 failures, `FEED_ALERT_AFTER` 1 day, `PUSH_ALERT_AFTER` 6h, `REALERT_AFTER` 1 day).

## Credentials

Local file `.credentials` (gitignored, chmod 600) holds: site user password, DB password, app login (calendar@oshyan.com), and an API bearer token named `claude-code`. The Gemini key lives only in the server `.env` (sourced originally from LogAssistant's key file). The Google OAuth client (`BETTERCAL_GOOGLE_CLIENT_ID` / `_SECRET`, see `docs/google-calendar.md`) lives only in the server `.env` too; connected accounts' refresh tokens are in the database, sealed with `BETTERCAL_SESSION_SECRET`.

## Updates and how you hear about them (2026-09-19)

Nothing on the box tells you about updates by itself: CloudPanel only shows "Update Available" in its web UI footer (and its notification bell, also UI-only), and Ubuntu only in the SSH login banner. CloudPanel's installer also turns Ubuntu's unattended upgrades off (`/etc/apt/apt.conf.d/20auto-upgrades` was `"0"` for both list refresh and upgrades), so nothing applied itself and a CloudPanel release plus ~230 Ubuntu security updates sat unnoticed for months.

Now: package indexes refresh daily. `unattended-upgrades` is configured for Ubuntu security updates only (`52unattended-upgrades-local`: mail to the owner on change, no automatic reboot, and an explicit blacklist for `nginx*`, `libnginx-*`, `php*`, `cloudpanel`, `percona-*`, `docker-*`, `containerd`, `postgresql-*`, which are not in its allowed origins anyway). A dry run showed 172 Ubuntu packages and none from those. It is **armed with `APT::Periodic::Unattended-Upgrade "1"`** in `20auto-upgrades` since the first manual update on 2026-09-19 (it was configured held until then). The config guard (above) has a warn-only `updates_pending` check that emails when a CloudPanel release or CloudPanel-repo/Percona/Docker packages are waiting; those stay manual on purpose.

What a manual update looks like (done 2026-09-19, log in `/root/clp-update-20260919.log`): take a Hetzner snapshot first (`hcloud server create-image --type snapshot cloudpanel-prod-1`, a few minutes, about 1 EUR/month while kept) plus the Better-Cal backup, then `clp-update` alone. It is not just the panel: it runs a full `apt upgrade` (that run took 291 packages in 8.5 minutes: CloudPanel 2.5.3 to 2.5.4, nginx 1.28 to 1.30, PHP 8.4.20 to 8.4.25, Percona 8.4.8 to 8.4.11, docker-ce, a kernel, libc6 and the Ubuntu backlog), so MySQL restarts once and, with Docker's `live-restore` off, every container restarts for under a minute. The guard's apt hook ran during it and found nothing to fix: the nginx.conf edit survived as a kept conffile. It leaves a reboot wanted when a kernel or libc6 came along (`/var/run/reboot-required`), which is a separate decision because it takes every site down for a minute or two.

## Host housekeeping (2026-09-20)

Things on the box that are not Better-Cal but were set up while working on it, so they are on record somewhere: journald is capped at 300M (`/etc/systemd/journald.conf.d/size.conf`; it had grown to 1G with no limit). The Discourse dev tree's logs (`/root/discourse-dev/log`, bind-mounted into the `discourse_dev` container) rotate at 100M with `copytruncate` via `/etc/logrotate.d/discourse-dev`, keeping two; `rails-dev.log` had reached 1.8G. Because logrotate runs those as the files' owner (`clp`, uid 1000, which is the container's `discourse` user) and `/root` is mode 700, `clp` has a traverse-only ACL on `/root` (`setfacl -m u:clp:x /root`). The Hangs reminder timer's boot-time failure is fixed in the open-invitations repo (`deploy/systemd`, commit 4f5c2ee) and applied to the live units. Disk after the 2026-09-19/20 cleanup: `/` 22G free of 75G (Docker image layers are 40G of it, all in use), `/home` 29G free of 49G.
