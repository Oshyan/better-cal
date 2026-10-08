#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Target host and paths come from scripts/deploy.env (copy deploy.env.example;
# ignored by git). Real environment variables win over the file.
if [ -f "${ROOT_DIR}/scripts/deploy.env" ]; then
  while IFS='=' read -r key value; do
    case "${key}" in ''|\#*) continue ;; esac
    if [ -z "${!key:-}" ]; then export "${key}=${value}"; fi
  done < "${ROOT_DIR}/scripts/deploy.env"
fi
for v in REMOTE APP_USER APP_DIR HEALTH_URL; do
  if [ -z "${!v:-}" ]; then
    echo "${v} is not set. Copy scripts/deploy.env.example to scripts/deploy.env and fill it in." >&2
    exit 1
  fi
done
BACKUP_DIR="${BACKUP_DIR:-/home/bettercal-backups}"
if [ -z "${DOCROOT:-}" ]; then
  echo "DOCROOT is not set (the web server's document root; see scripts/deploy.env.example)." >&2
  exit 1
fi

# Production deploys ship main. Feature branches go to the dev instance via
# scripts/deploy-dev.sh; FORCE_BRANCH=1 overrides for the rare deliberate
# exception.
BRANCH="$(git -C "${ROOT_DIR}" branch --show-current)"
if [ "${BRANCH}" != "main" ] && [ "${FORCE_BRANCH:-0}" != "1" ]; then
  echo "Refusing to deploy branch '${BRANCH}' to production. Use scripts/deploy-dev.sh, or FORCE_BRANCH=1." >&2
  exit 1
fi

# One shared ssh connection, loud failures (see the file for why).
source "${ROOT_DIR}/scripts/deploy-lib.sh"

# Production ships the commit, not the working tree: uncommitted edits (a
# second session's half-finished work included) never reach the server, and
# the tests below run on exactly the files that ship. Commit first, then deploy.
DEPLOY_COMMIT="$(git -C "${ROOT_DIR}" rev-parse HEAD)"
DEPLOY_SNAPSHOT="$(mktemp -d /tmp/bc-deploy.XXXXXX)"
git -C "${ROOT_DIR}" archive "${DEPLOY_COMMIT}" | tar -x -C "${DEPLOY_SNAPSHOT}"
# Composer's libraries aren't in the commit; the sabre-backed tests need them.
# Copied, not linked: Composer's autoloader maps the app's own classes relative
# to the real vendor path, so a symlink would test the working tree's code.
if [ -d "${ROOT_DIR}/server/vendor" ]; then cp -R "${ROOT_DIR}/server/vendor" "${DEPLOY_SNAPSHOT}/server/vendor"; fi
echo "Deploying $(git -C "${ROOT_DIR}" log -1 --format='%h %s' "${DEPLOY_COMMIT}") (version $(cat "${DEPLOY_SNAPSHOT}/VERSION"))"
UNCOMMITTED="$(git -C "${ROOT_DIR}" status --porcelain | wc -l | tr -d ' ')"
if [ "${UNCOMMITTED}" != "0" ]; then
  echo "  ${UNCOMMITTED} uncommitted change(s) in the working tree are not included."
fi

# Pre-flight gate. Every live break so far (blank app from a stray import, a
# dead "+ New" button, a frozen agenda) was a broken reference that a test run
# would have caught — but deploy never ran the tests. It does now. Set
# SKIP_TESTS=1 only when deliberately shipping a known-red tree.
#
# The modulepreload block and the service worker's version are no longer
# generated here: the app fills them in as it serves index.html and sw.js
# (server/src/Http/AppShell.php), so the committed files are what ships.
if [ "${SKIP_TESTS:-0}" != "1" ]; then
  echo "== pre-flight tests =="
  node --experimental-vm-modules "${DEPLOY_SNAPSHOT}/web/tests/static.mjs" 2>/dev/null | tail -1
  # The frontend works in the viewer's zone, so the suite runs in several: west
  # and east of UTC, UTC itself, a half-hour offset, and a southern-hemisphere
  # DST zone past +12. It used to pass only in Pacific time, which hid all-day
  # events moving a day for anyone east of their event's zone.
  for zone in America/Los_Angeles UTC Europe/Berlin Asia/Kolkata Pacific/Auckland; do
    printf '%-20s ' "${zone}"
    TZ="${zone}" node "${DEPLOY_SNAPSHOT}/web/tests/smoke.mjs" 2>/dev/null | tail -1
  done
  php "${DEPLOY_SNAPSHOT}/server/tests/run.php" | tail -1
  # Vendored frontend libraries must be exactly the pinned releases.
  node "${DEPLOY_SNAPSHOT}/scripts/vendor.mjs" --verify | tail -1
  bash "${DEPLOY_SNAPSHOT}/scripts/tests/deploy-security.sh" | tail -1
fi

stage "connect"
ssh_open

# Back up before anything changes, keeping the newest 5 app and database
# copies. The reviewed helper runs as root only to control the private output
# directory; it drops to APP_USER for every read from the app, .env and the
# database. A failed backup stops the deploy; SKIP_BACKUP=1 skips it deliberately.
if [ "${SKIP_BACKUP:-0}" != "1" ]; then
  stage "backup"
  printf -v app_dir_q '%q' "${APP_DIR}"
  printf -v app_user_q '%q' "${APP_USER}"
  printf -v backup_dir_q '%q' "${BACKUP_DIR}"
  rssh "sudo -n env APP_DIR=${app_dir_q} APP_USER=${app_user_q} BACKUP_DIR=${backup_dir_q} bash -s" \
    < "${DEPLOY_SNAPSHOT}/scripts/deploy-backup.sh"
fi

stage "rsync code"
deploy_rsync "${APP_DIR}" "${DEPLOY_SNAPSHOT}"

stage "composer + migrate + link"
rssh "APP_DIR='${APP_DIR}' DOCROOT='${DOCROOT}' APP_USER='${APP_USER}' bash -s" <<'EOF'
set -euo pipefail
# .env is provisioned once, outside deployment. Never repair it through this
# APP_USER-owned directory: a substituted symlink must not become a root path.
if sudo -n -u "${APP_USER}" -- test -L "${APP_DIR}/.env" \
  || ! sudo -n -u "${APP_USER}" -- test -f "${APP_DIR}/.env" \
  || ! sudo -n -u "${APP_USER}" -- test -r "${APP_DIR}/.env"; then
  echo "${APP_DIR}/.env must be a readable regular file, not a symlink; provision it before deploying." >&2
  exit 1
fi
sudo -u "${APP_USER}" bash -c "cd ${APP_DIR}/server && composer install --no-dev --quiet --no-interaction"
# Known advisories against the locked PHP dependencies: reported, not blocking.
# A finding means "look at it", not "roll back the deploy in progress".
echo "-- composer audit --"
sudo -u "${APP_USER}" bash -c "cd ${APP_DIR}/server && composer audit --no-dev --locked --no-interaction 2>&1 | tail -20" || true
sudo -u "${APP_USER}" php "${APP_DIR}/server/bin/migrate.php"
# Docroot -> app/server/public. Its parent belongs to APP_USER, so every
# pathname operation there runs as APP_USER rather than lending root to a
# replaceable path.
current_docroot="$(sudo -n -u "${APP_USER}" -- readlink "${DOCROOT}" 2>/dev/null || true)"
if [ "${current_docroot}" != "${APP_DIR}/server/public" ]; then
  sudo -n -u "${APP_USER}" -- rm -rf "${DOCROOT}"
  sudo -n -u "${APP_USER}" -- ln -s "${APP_DIR}/server/public" "${DOCROOT}"
fi
# Cron for worker (idempotent); the log sits beside the app directory.
WORKER_LOG="$(dirname "${APP_DIR}")/worker.log"
sudo -u "${APP_USER}" bash -c "crontab -l 2>/dev/null | grep -q worker.php || (crontab -l 2>/dev/null; echo \"* * * * * php ${APP_DIR}/server/bin/worker.php >> ${WORKER_LOG} 2>&1\") | crontab -"
EOF

# --- CalDAV nginx block (one-time manual change; NOT applied by this script) --
# The /dav endpoint is served by server/public/dav.php (sabre/dav). Add this
# location to the site's nginx server block, above/alongside the existing PHP
# location, then reload nginx (docs/install.md has the full block):
#
#   location ^~ /dav {
#     include fastcgi_params;                          # or the site's fastcgi include
#     fastcgi_param SCRIPT_FILENAME $document_root/dav.php;
#     fastcgi_param PATH_INFO $uri;
#     fastcgi_pass unix:/run/php/php8.4-fpm.sock;      # match the existing fastcgi_pass
#   }
#
#   # Optional, lets clients auto-discover the CalDAV root:
#   location = /.well-known/caldav { return 301 /dav/; }
#
# Until the block is applied, the endpoint also answers directly at
# /dav.php/ (dav.php adjusts its base URI automatically).

stage "smoke"
sleep 1
# /health proves PHP and the database answer. The server-side smoke proves the
# events window, the single-event record and the calendars listing actually
# serialise against the real data: a serializer refactor once 500ed the window
# while this script printed "Deploy OK" under a green /health.
# (curl on its own line: inside `curl && echo`, set -e ignored a failing /health.)
curl -fsS --max-time 10 "${HEALTH_URL}"
echo
rssh "sudo -u ${APP_USER} php ${APP_DIR}/server/bin/smoke.php"
echo "Deploy OK"
