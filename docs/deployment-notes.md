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

Background work reports into `system_health` (one row per job type, feed, and push device). Transitions land in Activity under "System"; the current state is on Settings → System. When SMTP is configured (`BETTERCAL_SMTP_HOST` / `BETTERCAL_SMTP_FROM`), a streak that lasts earns one email and one on recovery, never one per failure. Per-user subjects (a feed, a device) go to that user's account email; system-wide ones (a job type) go to `BETTERCAL_ALERT_EMAIL`, else to the first user. The alert job runs every ten minutes so a broken SMTP is one failed attempt per ten minutes. Thresholds live in `SystemHealth` (`JOB_ALERT_AFTER` 1h + 3 failures, `FEED_ALERT_AFTER` 1 day, `PUSH_ALERT_AFTER` 6h, `REALERT_AFTER` 1 day).

## Credentials

Local file `.credentials` (gitignored, chmod 600) holds: site user password, DB password, app login (calendar@oshyan.com), and an API bearer token named `claude-code`. The Gemini key lives only in the server `.env` (sourced originally from LogAssistant's key file).
