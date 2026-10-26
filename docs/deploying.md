# Maintainer Deploys

These are the tools the maintainer uses to ship Better-Cal to a server from a separate machine. You don't need any of them to install or update your own copy: [Installing](install.md) covers that, from the release zip or a git checkout.

## The Deploy Script

`scripts/deploy.sh` updates a server over rsync and ssh. It ships the last commit, not the working tree, so commit first. Before connecting, it runs the server, smoke and static suites, the deploy-security checks, the dependency-pin checks and the vendor check on exactly those files, and refuses to ship a red tree. On the server it backs up the app directory and the MySQL or MariaDB database before changing anything, and finishes with the health check and `smoke.php`. It assumes a Debian-style host with sudo, Composer, mysqldump, `flock` and cron, and refuses an SQLite database (use SQLite's own online backup for that).

**Configuration** comes only from `scripts/deploy.env`, which git ignores; copy `scripts/deploy.env.example` (it lists every key and the accepted format), then `chmod 600 scripts/deploy.env`. The file must belong to you and not be a symlink, and holds literal `KEY=VALUE` lines without shell quoting or expansion; unknown and duplicate keys are refused. Paths are plain absolute ASCII paths without spaces or shell punctuation. Inherited variables such as `REMOTE` and `APP_DIR` are ignored, and each script prints its target and configuration source before acting. SSH connection options belong in your SSH config.

**One-off flags** go in the command's environment, not `deploy.env`, each `0` or `1`: `SKIP_TESTS`, `FORCE_BRANCH`, `CLONE_DB`, `MOVE_INTEGRITY_QUIESCED`, `FEED_CREDENTIALS_QUIESCED` and `SKIP_BACKUP`. Skipping the backup prints a warning and requires typing `skip backup` at an interactive terminal, so unattended deploys always back up.

**Backups** go to `BACKUP_DIR`, which must be one direct child of `/home` or `/var/backups`. The deploy creates it as `root:root` mode `0700`, writes each archive as mode `0600`, and keeps the newest five app and database copies; the app account supplies the data but can't create, replace or remove backups. An install deployed by an older version of the script may still have a `backups` folder beside the app: once a new backup exists under `BACKUP_DIR`, archive or remove that folder yourself.

**The worker log.** The deploy creates `worker.log` as the app account with mode 0600 (making an existing one private without erasing it), and its managed cron line uses umask 0077. A log rotator must keep those permissions; custom cron lines aren't rewritten.

## Settings File

For the root-backed deploy, provision `.env.php` once as `root:APP_USER` mode `0640` (`chown root:bettercal .env.php && chmod 640 .env.php`). The deploy checks that it's a readable regular file and refuses a symlink or both `.env` and `.env.php` at once, but never changes its owner or mode. To convert a root-owned `.env`, run `sudo php server/bin/settings.php --convert`, which keeps its owner, group and mode.

To change settings without editing the file on the server, put them in `scripts/server.env` on your own machine (git ignores it; `KEY=VALUE` lines) and run `scripts/push-settings.sh` (or `scripts/push-settings.sh dev` with `scripts/server-dev.env`). It adds or replaces only those keys, keeps every other line (the guard line included), saves a copy to `/root/bettercal-env-backups` first, refuses a symlink, keeps the file's owner and mode, and never prints a value. A deploy never writes settings. They apply on the next request; nothing needs restarting.

## Development Deployments

`scripts/deploy-dev.sh` ships the working tree, on any branch, to a second install on the same host, with its own directory and database. Provision `DEV_DIR`, `DEV_DB`, the dev settings file and its own `BETTERCAL_SESSION_SECRET` first. Database accounts and grants are yours to set up: the deploy checks them but never creates, locks, unlocks or rewrites them.

Three settings in `scripts/deploy.env` trade security against convenience:

- **`DEV_ISOLATION_MODE=strict|shared`** (default `strict`). Strict needs separate Unix, database and PHP-FPM identities, and checks that neither app can read the other's database or settings, or write to them by any route (groups, ACLs, ownership that allows chmod, writable parent folders). An unsafe or uncheckable layout stops the deploy. `shared` allows reusing identities and credentials and warns on every deploy; the directories, database names and session secrets must still differ. A shared PHP-FPM service is never stopped or restarted, so if opcode timestamp checks are off, changed dev code may stay stale until you reload it.
- **`DEV_EXTERNAL_SERVICES=disabled|enabled`** (default `disabled`). Enabling it allows integrations you configure in the dev settings, with a warning that mail, push, AI, maps and connected calendars can have real side effects or cost. A clone never copies production's Google connections or active plugin settings; set those up again in dev. Receive-only ICS subscriptions aren't affected by this switch.
- **`DEV_CLONE_SUBSCRIPTIONS=preserve|strip`** (default `preserve`). A clone re-encrypts ICS subscription addresses with the dev session secret so they keep syncing; `strip` removes them and leaves their events as local snapshots.

**`CLONE_DB=1`** copies production into dev. It imports into a private, randomly named staging schema first, checks that neither app account can reach it, cleans and verifies it there, and only then replaces the dev database. Copied sessions, remembered devices, API tokens, push subscriptions, outbound feeds, Google connections, active jobs and plugin integration settings are removed; plugins and Google-backed calendars stay as inert snapshots. Every copied password is replaced with `DEV_LOGIN_PASSWORD`, which belongs only in `deploy.env`.
